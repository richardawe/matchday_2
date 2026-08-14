#!/bin/zsh
set -e
OUT="public/matchday-warriors-trailer.mp4"
TMP="$(mktemp -d /private/tmp/matchday-trailer.XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

clubs=(arsenal aston-villa bournemouth brentford brighton chelsea coventry crystal-palace everton fulham hull ipswich leeds liverpool manchester-city manchester-united newcastle nottingham-forest sunderland tottenham)
names=("ARSENAL" "ASTON VILLA" "BOURNEMOUTH" "BRENTFORD" "BRIGHTON" "CHELSEA" "COVENTRY CITY" "CRYSTAL PALACE" "EVERTON" "FULHAM" "HULL CITY" "IPSWICH TOWN" "LEEDS UNITED" "LIVERPOOL" "MANCHESTER CITY" "MANCHESTER UNITED" "NEWCASTLE UNITED" "NOTTINGHAM FOREST" "SUNDERLAND" "TOTTENHAM HOTSPUR")
font="/System/Library/Fonts/Supplemental/Arial Bold.ttf"

ffmpeg -y -f lavfi -i color=c=0x070807:s=1080x1920:d=2:r=30 -vf "drawtext=fontfile='$font':text='MATCHDAY':fontcolor=0xd6a64d:fontsize=100:x=(w-text_w)/2:y=760,drawtext=fontfile='$font':text='EVERY MATCH BECOMES MYTH':fontcolor=white:fontsize=34:x=(w-text_w)/2:y=900,fade=t=in:st=0:d=0.5,fade=t=out:st=1.4:d=0.6" -c:v libx264 -pix_fmt yuv420p "$TMP/intro.mp4"

for i in {1..20}; do
  idx=$((i-1)); img="public/warriors/${clubs[$i]}.png"; name="${names[$i]}"
  ffmpeg -y -loop 1 -i "$img" -t 1.85 -vf "scale=1080:1440:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=0x070807,zoompan=z='min(zoom+0.0007,1.055)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=56:s=1080x1920:fps=30,drawbox=x=0:y=0:w=iw:h=170:color=black@0.38:t=fill,drawtext=fontfile='$font':text='${name}':fontcolor=white:fontsize=44:x=(w-text_w)/2:y=62,drawtext=fontfile='$font':text='${i} / 20':fontcolor=0xd6a64d:fontsize=24:x=(w-text_w)/2:y=120,fade=t=in:st=0:d=0.24,fade=t=out:st=1.55:d=0.3" -an -c:v libx264 -preset medium -crf 19 -pix_fmt yuv420p "$TMP/$(printf '%02d' $i).mp4"
done

ffmpeg -y -f lavfi -i color=c=0x070807:s=1080x1920:d=2.4:r=30 -vf "drawtext=fontfile='$font':text='TWENTY ARMIES':fontcolor=white:fontsize=70:x=(w-text_w)/2:y=760,drawtext=fontfile='$font':text='ONE THRONE':fontcolor=0xd6a64d:fontsize=108:x=(w-text_w)/2:y=865,drawtext=fontfile='$font':text='THE 2026 / 27 CAMPAIGN':fontcolor=white:fontsize=27:x=(w-text_w)/2:y=1040,fade=t=in:st=0:d=0.5,fade=t=out:st=1.8:d=0.6" -c:v libx264 -pix_fmt yuv420p "$TMP/outro.mp4"

print "file '$TMP/intro.mp4'" > "$TMP/list.txt"
for i in {1..20}; do print "file '$TMP/$(printf '%02d' $i).mp4'" >> "$TMP/list.txt"; done
print "file '$TMP/outro.mp4'" >> "$TMP/list.txt"
ffmpeg -y -f concat -safe 0 -i "$TMP/list.txt" -c copy "$TMP/video.mp4"

# Original war cadence: deep drum fundamentals, marching accents and a low ceremonial drone.
ffmpeg -y -f lavfi -i "aevalsrc=0.14*sin(2*PI*55*t)+0.06*sin(2*PI*82.41*t)+if(lt(mod(t\,0.5)\,0.11)\,0.7*sin(2*PI*(75-360*mod(t\,0.5))*mod(t\,0.5))*exp(-28*mod(t\,0.5))\,0)+if(lt(mod(t+0.25\,2)\,0.09)\,0.9*sin(2*PI*60*mod(t+0.25\,2))*exp(-32*mod(t+0.25\,2))\,0):s=48000:d=41.4" -af "lowpass=f=500,acompressor=threshold=0.35:ratio=4:attack=5:release=120,afade=t=in:st=0:d=1.2,afade=t=out:st=39:d=2.4" -c:a aac -b:a 192k "$TMP/audio.m4a"

ffmpeg -y -i "$TMP/video.mp4" -i "$TMP/audio.m4a" -c:v copy -c:a aac -b:a 192k -shortest -movflags +faststart "$OUT"
echo "$OUT"
