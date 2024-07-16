@echo off

REM Clearing the screen
test&cls

REM Create Cache folder if it doesn't exist
echo Cleaning up the current folder...

REM Checking if the Cache folder exists
IF NOT EXIST "Cache" (
    MKDIR "Cache"
)

REM Remove any previous mp4 files
IF EXIST "E:\repos\evDashVisualizer\*.mp4" (
    DEL /Q *.mp4
)

echo [92mCurrent folder has been cleaned up[0m
echo.

echo Creating an mjpeg animation with the following settings: speedup=7 zoom=12...
PHP index.php filename=demo_data.json speedup=7 zoom=12 info=1
echo [92mMJPeg animation created[0m
echo.

echo Converting the mjpeg animation to an mp4 file...

REM 4k
ffmpeg -hide_banner -loglevel error -i demo_data_map.mjpeg -pix_fmt yuv420p -b:v 4000k -c:v libx264 -vf scale=3840:2160 final_result.mp4

REM 2k
REM FFMPEG -i demo_data_map.mjpeg -pix_fmt yuv420p -b:v 4000k -c:v libx264 final_result.mp4
echo [92mMP4 file created[0m
echo.

echo Removing leftover files...
DEL demo_data_map.mjpeg
DEL /S /Q cache\* >nul 2>&1
echo [92mLeftover files removed[0m
echo.

REM EXIT /B
PAUSE