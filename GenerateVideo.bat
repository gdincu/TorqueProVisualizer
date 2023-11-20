DEL /Q *.mp4

PHP index.php filename=demo_data.json speedup=3 zoom=14 info=1

FFMPEG -i demo_data_map.mjpeg -pix_fmt yuv420p -b:v 4000k -c:v libx264 final_result.mp4

DEL demo_data_map.mjpeg

DEL /S /Q cache\*

EXIT /B