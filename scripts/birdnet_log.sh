#!/usr/bin/env bash
journalctl --no-hostname -q -o short -fu birdnet_analysis -u birdnet_recording | sed "s/$(date "+%b %d ")//g;s/${HOME//\//\\/}\///g;/Line/d;/find/d;/systemd/d;s/ .*\[.*\]: /---/"
