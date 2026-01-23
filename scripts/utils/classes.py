import datetime
import os
import re

from tzlocal import get_localzone


class Detection:
    def __init__(self, start_datetime, stop_datetime, scientific_name, common_name, confidence):
        self.start_datetime = start_datetime
        self.stop_datetime = stop_datetime
        self.date = self.start_datetime.strftime("%Y-%m-%d")
        self.time = self.start_datetime.strftime("%H:%M:%S")
        self.iso8601 = self.start_datetime.astimezone(get_localzone()).isoformat()
        self.week = self.start_datetime.isocalendar()[1]
        self.confidence = round(float(confidence), 4)
        self.confidence_pct = round(self.confidence * 100)
        self.species = scientific_name
        self.scientific_name = scientific_name
        self.common_name = common_name
        self.common_name_safe = self.common_name.replace("'", "").replace(" ", "_")
        self.file_name_extr = None

    def __str__(self):
        return f'Detection({self.species}, {self.common_name}, {self.confidence}, {self.iso8601})'


class ParseFileName:
    def __init__(self, file_name):
        self.file_name = file_name
        name = os.path.splitext(os.path.basename(file_name))[0]
        date_created = re.search('^[0-9]+-[0-9]+-[0-9]+', name).group()
        time_created = re.search('[0-9]+:[0-9]+:[0-9]+$', name).group()
        self.file_date = datetime.datetime.strptime(f'{date_created}T{time_created}', "%Y-%m-%dT%H:%M:%S")
        self.root = name

        ident_match = re.search("RTSP_[0-9]+-", file_name)
        self.RTSP_id = ident_match.group() if ident_match is not None else ""

    @property
    def iso8601(self):
        current_iso8601 = self.file_date.astimezone(get_localzone()).isoformat()
        return current_iso8601

    @property
    def week(self):
        week = self.file_date.isocalendar()[1]
        return week
