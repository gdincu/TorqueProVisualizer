ffmpeg -hide_banner -loglevel error -y -i final_result_4K.mp4 -vf "drawtext=fontfile=C\\:/Windows/Fonts/RockwellNova-ExtraBold.ttf:text='evDashVisualizer 4K':x=(w-text_w)/2:y=(h-text_h)/2:enable='between(t,1,6)':fontsize=240:fontcolor=black:box=1:boxborderw=33:boxcolor=white@0.25" -codec:a copy output.mp4