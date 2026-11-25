@echo off
title Special Delicacies Reverb Daemon
echo Starting Laravel Reverb persistent daemon for Special Delicacies...
cd /d "%~dp0\.."
powershell -ExecutionPolicy Bypass -NoProfile -File "%~dp0reverb-daemon.ps1"
pause
