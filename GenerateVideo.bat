@echo off

REM Clearing the screen
test&cls

REM Create Cache folder if it doesn't exist
echo Cleaning up the current folder...

REM Checking if the Cache folder exists
IF NOT EXIST "Cache" (
    MKDIR "Cache"
) ELSE (
DEL /S /Q cache\* >nul 2>&1
)

REM Remove any previous mp4 & mjpeg files
IF EXIST "E:\repos\evDashVisualizer\*.mp4" (
    DEL /Q *.mp4
)

IF EXIST "E:\repos\evDashVisualizer\*.mjpeg" (
    DEL /Q *.mjpeg
)

echo [92mCurrent folder has been cleaned up[0m
echo.

:: Prompt the user for the speedup
set /p speedup="Enter the speedup factor (e.g. 7 for 7x speed): "

:: Prompt the user for the zoom level
set /p zoom="Enter the zoom level (e.g. 11 - the higher the value the greater the zoom in effect): "

:: Prompt the user for the output resolution
set /p resolution="Enter your choice (2K or 4K): "

echo.
echo Creating an mjpeg animation with the following settings: [92mspeedup=%speedup% zoom=%zoom%[0m...
PHP index.php filename=demo_data.json speedup=%speedup% zoom=%zoom% info=1
echo [92mMJPeg animation created[0m
echo.

echo Converting the mjpeg animation to an mp4 file with a [92m%resolution% resolution[0m...

if /I "%resolution%"=="2k" (
    FFMPEG -hide_banner -loglevel error -i demo_data_map.mjpeg -pix_fmt yuv420p -b:v 4000k -c:v libx264 final_result_2K.mp4
) else (
    ffmpeg -hide_banner -loglevel error -i demo_data_map.mjpeg -pix_fmt yuv420p -b:v 4000k -c:v libx264 -vf scale=3840:2160 final_result_4K.mp4
)

echo [92mMP4 file created[0m
echo.

echo Removing leftover files...
DEL demo_data_map.mjpeg
DEL /S /Q cache\* >nul 2>&1
echo [92mLeftover files removed[0m
echo.

REM EXIT /B
PAUSE