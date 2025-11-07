"""Module to handle communication with the BirdWeather API."""

import requests
import logging
import datetime
import subprocess
import tenacity

import gzip
import io
import soundfile
from typing import Any, Dict, List, Optional
from .helpers import Detection

log = logging.getLogger(__name__)


def wav_to_flac(soundscape_file: str) -> bytes:
    """Convert wav file to FLAC and compress."""
    data, samplerate = soundfile.read(soundscape_file)
    buf = io.BytesIO()
    soundfile.write(buf, data, samplerate, format="FLAC")
    flac_data = buf.getvalue()
    return gzip.compress(flac_data)


def mp3_to_flac(soundscape_file: str) -> bytes:
    """Convert mp3 file to FLAC and compress."""
    result = subprocess.run(
        ["ffmpeg", "-i", soundscape_file, "-f", "flac", "pipe:1"],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        check=True,
    )
    return gzip.compress(result.stdout)


@tenacity.retry(
    reraise=True,
    wait=tenacity.wait_exponential(),
    stop=tenacity.stop_after_delay(90),
)
def get_birdweather_species_id(sci_name: str, com_name: str) -> int:
    """Lookup a BirdWeather species ID based on the species scientific and common names."""
    species_url = "https://app.birdweather.com/api/v1/species/lookup"
    resp = requests.post(
        url=species_url,
        json={"species": [f"{sci_name}_{com_name}"]},
        timeout=20,
    )
    data = resp.json()
    if not data["success"] or len(data["species"]) != 1:
        raise ValueError(f"Unexpected species lookup response: {data}")
    species = next(iter(data["species"].values()))
    return species["id"]


@tenacity.retry(
    reraise=True,
    wait=tenacity.wait_exponential(),
    stop=tenacity.stop_after_delay(90),
)
def query_birdweather_detections(
    birdweather_id: str,
    species_id: int,
    detection_datetime: datetime.datetime,
) -> List[Dict[str, Any]]:
    """Query detections from the BirdWeather API for specific station, species and time."""
    detections_url = f"https://app.birdweather.com/api/v1/stations/{birdweather_id}/detections"
    resp = requests.get(
        url=detections_url,
        data={
            "speciesId": species_id,
            "from": detection_datetime.isoformat(),
            "to": detection_datetime.isoformat(),
        },
        timeout=20,
    )
    data = resp.json()
    if not data["success"]:
        raise ValueError(f"Unexpected detections query response: {data}")
    return data["detections"]


@tenacity.retry(
    reraise=True,
    wait=tenacity.wait_exponential(),
    stop=tenacity.stop_after_delay(90),
)
def post_soundscape(
    birdweather_id: str, detection_datetime: datetime.datetime, soundscape: bytes
) -> Optional[int]:
    """Upload soundscape bytes to BirdWeather."""
    soundscape_url = (
        f"https://app.birdweather.com/api/v1/stations/{birdweather_id}/"
        f"soundscapes?timestamp={detection_datetime.isoformat()}"
    )
    resp = requests.post(
        url=soundscape_url,
        data=soundscape,
        timeout=20,
        headers={
            "Content-Type": "application/octet-stream",
            "Content-Encoding": "gzip",
        },
    )
    data = resp.json()
    if not data.get("success"):
        log.error(data.get("message"))
        raise ValueError(data.get("message"))
    return data["soundscape"]["id"]


def convert_and_post_soundscape_to_birdweather(
    birdweather_id: str, detection_datetime: datetime.datetime, soundscape_file: str
) -> int:
    """Upload a soundscape file to BirdWeather."""
    try:
        if soundscape_file.endswith(".wav"):
            gzip_flac_data = wav_to_flac(soundscape_file)
        elif soundscape_file.endswith(".mp3"):
            gzip_flac_data = mp3_to_flac(soundscape_file)
        else:
            raise ValueError(f"File extension not supported: {soundscape_file}")
    except Exception as e:
        log.error(f"Error during FLAC conversion: {e}")
        raise

    soundscape_id = post_soundscape(birdweather_id, detection_datetime, gzip_flac_data)
    if not soundscape_id:
        raise ValueError("Posting soundscape to BirdWeather didn't return a valid soundscape ID")
    return soundscape_id


@tenacity.retry(
    reraise=True,
    wait=tenacity.wait_exponential(),
    stop=tenacity.stop_after_delay(90),
)
def post_detection_to_birdweather(
    detection: Detection,
    soundscape_id: str,
    soundscape_datetime: datetime.datetime,
    birdweather_id: str,
    latitude: float,
    longitude: float,
    model: str,
):
    """Upload a detection to BirdWeather."""
    detection_url = f"https://app.birdweather.com/api/v1/stations/{birdweather_id}/detections"
    data = {
        "timestamp": detection.iso8601,
        "lat": latitude,
        "lon": longitude,
        "soundscapeId": soundscape_id,
        "soundscapeStartTime": (detection.start_datetime - soundscape_datetime).seconds,
        "soundscapeEndTime": (detection.stop_datetime - soundscape_datetime).seconds,
        "commonName": detection.common_name,
        "scientificName": detection.scientific_name,
        "algorithm": "2p4" if model == "BirdNET_GLOBAL_6K_V2.4_Model_FP16" else "alpha",
        "confidence": detection.confidence,
    }
    log.debug(data)
    response = requests.post(detection_url, json=data, timeout=20)
    log.info("Detection POST Response Status - %d", response.status_code)
    if response.status_code != 201:
        raise ValueError(f"Detection POST unsuccessful: {response.json()}")
