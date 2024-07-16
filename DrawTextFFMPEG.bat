@echo off
setlocal enabledelayedexpansion

REM Clearing the screen
test&cls

echo Adding custom text to the mp4 file...

REM Constructing the comand using a string builder
set "cmd=ffmpeg -hide_banner -loglevel error -y -i final_result_2K.mp4 -vf "

REM Drawtext #1
set "cmd=!cmd!"drawtext=fontfile=C\\:/Windows/Fonts/RockwellNova-ExtraBold.ttf:text='TEXT1':x=(w-text_w)/2:y=(h-text_h)/2:enable='between(t,1,3)':fontsize=240:fontcolor=black:box=1:boxborderw=33:boxcolor=white@0.25,"

REM Drawtext #2
set "cmd=!cmd!drawtext=fontfile=C\\:/Windows/Fonts/RockwellNova-ExtraBold.ttf:text='TEXT2':x=(w-text_w)/2:y=(h-text_h)/2:enable='between(t,4,6)':fontsize=240:fontcolor=black:box=1:boxborderw=33:boxcolor=white@0.25""

REM Specifying an output file
set "cmd=!cmd! -codec:a copy output.mp4"

:: Execute the constructed command
!cmd!

echo [92mmp4 file updated successfully[0m
echo.

pause
