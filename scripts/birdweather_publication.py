"""Publish past detections to BirdWeather."""

import datetime
import gzip
import json
import logging
import os
import sqlite3
import sys
from typing import Any, Dict, List

import librosa
import pandas as pd
import requests
from icecream import ic  # noqa F041
from tzlocal import get_localzone
from utils.helpers import DB_PATH, get_settings

log = logging.getLogger(__name__)


def get_recent_detections(start_datetime: datetime.datetime = None) -> pd.DataFrame:
    # TODO: Clarify when to start looking through detections. Use date of last run?
    if start_datetime is None:
        start_datetime = datetime.datetime.now() - datetime.timedelta(days=3)
    conn = sqlite3.connect(DB_PATH)
    df = pd.read_sql_query(
        f"SELECT * from detections WHERE Date > DATE('{start_datetime.strftime('%Y-%m-%d')}')",
        conn,
    )
    conn.close()
    return df


def get_birdweather_species_id(sci_name: str, com_name: str) -> int:
    species_url = "https://app.birdweather.com/api/v1/species/lookup"
    resp = requests.post(url=species_url, json={"species": [f"{sci_name}_{com_name}"]})
    data = json.loads(resp.text)
    if not data["success"] or len(data["species"]) != 1:
        raise LookupError(
            f"Couldn't find birdweather species ID for {sci_name}_{com_name}"
        )
    species = next(iter(data["species"].values()))
    return species["id"]


def lookup_birdweather_detections(
    birdweather_id: str,
    species_id: int,
    soundscape_datetime: datetime.datetime,
    soundscape_duration: float,
) -> List[Dict[str, Any]]:
    detections_url = (
        f"https://app.birdweather.com/api/v1/stations/{birdweather_id}/detections"
    )
    resp = requests.get(
        url=detections_url,
        data={
            "speciesId": species_id,
            "from": soundscape_datetime.isoformat(),
            "to": (
                soundscape_datetime + datetime.timedelta(seconds=soundscape_duration)
            ),
        },
    )
    data = json.loads(resp.text)
    if not data["success"]:
        raise LookupError("Could not lookup detections from BirdWeather")
    return data["detections"]


def lookup_birdweather_soundscapes(
    birdweather_id: str,
    species_id: int,
    soundscape_datetime: datetime.datetime,
    soundscape_duration: float,
) -> List[Dict[str, Any]]:
    soundscapes_url = (
        f"https://app.birdweather.com/api/v1/stations/{birdweather_id}/soundscapes"
    )
    resp = requests.get(
        url=soundscapes_url,
        data={
            "from": soundscape_datetime.isoformat(),
            "to": (
                soundscape_datetime + datetime.timedelta(seconds=soundscape_duration)
            ).isoformat(),
            "speciesId": species_id,
        },
    )
    data = json.loads(resp.text)
    if not data["success"]:
        raise LookupError("Could not lookup soundscapes from BirdWeather")
    return data["soundscapes"]


def post_birdweather_soundscape(
    birdweather_id: str, soundscape_datetime: datetime.datetime, soundscape_file: str
) -> int:
    soundscape_url = f"https://app.birdweather.com/api/v1/stations/{birdweather_id}/soundscapes?timestamp={soundscape_datetime.isoformat()}"
    with open(soundscape_file, "rb") as f:
        mp3_data = f.read()
    gzip_mp3_data = gzip.compress(mp3_data)
    try:
        resp = requests.post(
            url=soundscape_url,
            data=gzip_mp3_data,
            timeout=30,
            headers={
                "Content-Type": "application/octet-stream",
                "Content-Encoding": "gzip",
            },
        )
        data = resp.json()
    except BaseException as e:
        log.error(f"Cannot POST soundscape: {e}")
        return
    if not data.get("success"):
        log.error(data.get("message"))
        return
    return data["soundscape"]["id"]


def setup_logging():
    logger = logging.getLogger()
    formatter = logging.Formatter("[%(name)s][%(levelname)s] %(message)s")
    handler = logging.StreamHandler(stream=sys.stdout)
    handler.setFormatter(formatter)
    logger.addHandler(handler)
    logger.setLevel(logging.INFO)
    global log
    log = logging.getLogger("birdweather_publication")


def main():

    conf = get_settings()
    if conf["BIRDWEATHER_ID"] == "":
        return

    # Loop through recent detections
    df = get_recent_detections()
    for row in df.itertuples():

        ic("Processing row: ", row)

        soundscape_datetime = datetime.datetime.strptime(
            f"{row.Date} {row.Time}", "%Y-%m-%d %H:%M:%S"
        ).astimezone(get_localzone())
        full_length_soundscape_duration = int(conf["RECORDING_LENGTH"])
        species_id = get_birdweather_species_id(row.Sci_Name, row.Com_Name)
        ic(soundscape_datetime, full_length_soundscape_duration, species_id)

        # Lookup detections present in BirdWeather within timeframe of this detection's soundscape
        birdweather_detections = lookup_birdweather_detections(
            conf["BIRDWEATHER_ID"],
            species_id,
            soundscape_datetime,
            full_length_soundscape_duration,
        )
        ic(birdweather_detections)

        # This detection's species is not present in BirdWeather
        if not any(
            [
                d["species"]["scientificName"] == row.Sci_Name
                for d in birdweather_detections
            ]
        ):

            # Post soundscape to birdweather
            soundscape_id = post_birdweather_soundscape(
                conf["BIRDWEATHER_ID"],
                soundscape_datetime,
                os.path.join(
                    conf["EXTRACTED"],
                    "By_Date",
                    soundscape_datetime.strftime("%Y-%m-%d"),
                    row.Com_Name.replace(" ", "_"),
                    row.File_Name,
                ),
            )
            ic("Posted soundscape: ", soundscape_id)

            soundscape_subset_duration = librosa.get_duration(
                filename=os.path.join(
                    conf["EXTRACTED"],
                    "By_Date",
                    soundscape_datetime.strftime("%Y-%m-%d"),
                    row.Com_Name.replace(" ", "_"),
                    row.File_Name,
                )
            )

            detections_url = f"https://app.birdweather.com/api/v1/stations/{conf['BIRDWEATHER_ID']}/detections"
            # TODO: Find out how to get actual detection start and end times, store them in DB?
            data = {
                "timestamp": soundscape_datetime.isoformat(),
                "lat": conf["LATITUDE"],
                "lon": conf["LONGITUDE"],
                "soundscapeId": soundscape_id,
                "soundscapeStartTime": 0.0,
                "soundscapeEndTime": soundscape_subset_duration,
                "commonName": row.Com_Name,
                "scientificName": row.Sci_Name,
                "algorithm": (
                    "2p4"
                    if conf["MODEL"] == "BirdNET_GLOBAL_6K_V2.4_Model_FP16"
                    else "alpha"
                ),
                "confidence": row.Confidence,
            }
            ic("Posting detection: ", data)
            try:
                resp = requests.post(url=detections_url, json=data, timeout=20)
                log.info(f"Detection POST Response Status - {resp.status_code:d}")
            except BaseException as e:
                log.error(f"Cannot POST detection: {e}")

            ic(
                "Successfully posted detection: ",
                soundscape_datetime.isoformat(),
                row.Sci_Name,
            )


if __name__ == "__main__":

    setup_logging()

    main()
