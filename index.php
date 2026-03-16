<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');
ini_set('memory_limit', '2048M');

if (!extension_loaded("curl") || !extension_loaded("gd")) {
    die("Enable curl and gd extensions in your php.ini\n");
}

include("global.php");
include("LiveData.php");

class EvDashboardOverview {
    private $jsonData;
    private $params;
    private $onlyStaticImage;
    private $image;
    private $imageMapBk;
    private $colors = [];
    private $width = 1920;
    private $height = 1080;
    private $darkMode;
    private $tileUrl;
    private $hideInfo;
    private $speedup;
    private $font;
    protected $tileSize = 256;
    
    private $tileCache = [];
    private $iconCache = [];
    private $liveData;

    function __construct() {
        if (PHP_SAPI === 'cli' && isset($_SERVER['argv'])) {
            foreach ($_SERVER['argv'] as $key => $value) {
                if ($key == 0) continue;
                parse_str($value, $args);
                foreach ($args as $k => $v) { $_GET[$k] = $v; }
            }
        }

        $this->tileUrl  = 'https://b.tile.openstreetmap.org/{z}/{x}/{y}.png';
        $this->darkMode = (bool)getNum("dark", 0);
        $this->hideInfo = (getNum("info", 1) == 0);
        $this->speedup  = getNum("speedup", 1);
        $this->liveData = new LiveData();
        $this->font     = __DIR__ . '/fonts/RobotoCondensed-Light.ttf';
    }

    private function prepareColors($img) {
        return [
            'white'     => imagecolorallocate($img, 255, 255, 255),
            'black'     => imagecolorallocate($img, 0, 0, 0),
            'red'       => imagecolorallocate($img, 255, 0, 0),
            'track_red' => imagecolorallocate($img, 255, 120, 120),
            'track_grn' => imagecolorallocate($img, 0, 204, 102),
            'osd_bg'    => imagecolorallocatealpha($img, $this->darkMode ? 0 : 255, $this->darkMode ? 0 : 255, $this->darkMode ? 0 : 255, $this->darkMode ? 72 : 48)
        ];
    }

    function preprocessData($jsonFileName, $onlyStaticImage = true) {
        $this->onlyStaticImage = $onlyStaticImage;
        $this->fileName = $jsonFileName;

        $raw = file_get_contents($jsonFileName);
        $data = "[" . rtrim(rtrim(trim($raw), "\n"), ",") . "]";
        $this->jsonData = json_decode($data, true);
        unset($raw);

        $this->params = [
            "keyframes" => 0, "maxOdoKm" => -1, "minOdoKm" => -1,
            "latMin" => -1, "latMax" => -1, "lonMin" => -1, "lonMax" => -1,
            "latStartPoint" => -1, "lonStartPoint" => -1
        ];

        foreach ($this->jsonData as &$row) {
            if (isset($row['speedKmhGPS']) && $row['speedKmhGPS'] != -1) $row['speedKmh'] = $row['speedKmhGPS'];
            if ($row['odoKm'] != 1.677721e7) {
                if ($this->params['maxOdoKm'] == -1 || $row['odoKm'] > $this->params['maxOdoKm']) $this->params['maxOdoKm'] = $row['odoKm'];
            } else { $row['odoKm'] = $this->params['maxOdoKm']; }

            $this->params['keyframes']++;
            if ($this->params['latStartPoint'] == -1 && $row['lat'] != -1) {
                $this->params['latStartPoint'] = $row['lat'];
                $this->params['lonStartPoint'] = $row['lon'];
            }
        }

        $res = getStr("res", "");
        $this->width  = ($res == "4K") ? 3840 : 1920;
        $this->height = ($res == "4K") ? 2160 : 1080;
        $this->params['latCenter'] = $this->params['latStartPoint'];
        $this->params['lonCenter'] = $this->params['lonStartPoint'];
        $this->params['zoom']      = getNum("zoom", 12);

        $this->image      = imagecreatetruecolor($this->width, $this->height);
        $this->imageMapBk = imagecreatetruecolor($this->width, $this->height);
        $this->colors     = $this->prepareColors($this->image);
        $this->renderMap();
    }

    private function getTileCached($x, $y, $z) {
        $key = "{$z}_{$x}_{$y}";
        if (isset($this->tileCache[$key])) return $this->tileCache[$key];
        $url = str_replace(['{z}', '{x}', '{y}'], [$z, $x, $y], $this->tileUrl);
        $tileData = fetchTile($url);
        return $this->tileCache[$key] = $tileData ? imagecreatefromstring($tileData) : imagecreatetruecolor($this->tileSize, $this->tileSize);
    }

    function renderMap() {
        $outputFile = str_replace(".json", "", $this->fileName) . ($this->onlyStaticImage ? '_map.jpg' : '_map.mjpeg');
        $fp = fopen($outputFile, 'wb');
        $eleStep = $this->width / max(1, $this->params['keyframes']);
		
        for ($frame = 0; $frame < $this->params['keyframes']; $frame += $this->speedup) {
            if ($this->onlyStaticImage) $frame = $this->params['keyframes'] - 1;

            // 1. Calculate Tile Viewport
            $centerX = lonToTile($this->params['lonCenter'], $this->params['zoom']);
            $centerY = latToTile($this->params['latCenter'], $this->params['zoom']);
            $startX = floor($centerX - ($this->width / $this->tileSize) / 2);
            $startY = floor($centerY - ($this->height / $this->tileSize) / 2);
            $offsetX = floor(($this->width / 2) - ($centerX - $startX) * $this->tileSize);
            $offsetY = floor(($this->height / 2) - ($centerY - $startY) * $this->tileSize);

            // 2. Refresh Map Background
            for ($x = $startX; $x <= ceil($centerX + ($this->width / $this->tileSize) / 2); $x++) {
                for ($y = $startY; $y <= ceil($centerY + ($this->height / $this->tileSize) / 2); $y++) {
                    imagecopy($this->imageMapBk, $this->getTileCached($x, $y, $this->params['zoom']), ($x - $startX) * $this->tileSize + $offsetX, ($y - $startY) * $this->tileSize + $offsetY, 0, 0, $this->tileSize, $this->tileSize);
                }
            }

            imagecopy($this->image, $this->imageMapBk, 0, 0, 0, 0, $this->width, $this->height);
            if ($this->darkMode) imagefilter($this->image, IMG_FILTER_NEGATE);
            $overlay = imagecolorallocatealpha($this->image, 0, 0, 0, $this->darkMode ? 100 : 127);
            imagefilledrectangle($this->image, 0, 0, $this->width, $this->height, $overlay);

            // 3. Draw Track Logic ($O(N) but optimized with local variables)
            $this->liveData->initData();
            $prev = null;
            for ($i = 0; $i <= $frame; $i++) {
                $row = $this->jsonData[$i];
                $this->liveData->processRow($row);
                if ($row['lat'] == -1) continue;

                $currX = ($this->width / 2) - $this->tileSize * ($centerX - lonToTile($row['lon'], $this->params['zoom']));
                $currY = ($this->height / 2) - $this->tileSize * ($centerY - latToTile($row['lat'], $this->params['zoom']));

                if ($prev) {
                    // Map Path
                    $color = (strpos($row['CarId'], '_r') !== false) ? $this->colors['track_red'] : $this->colors['track_grn'];
                    imagesetthickness($this->image, 3);
                    imageline($this->image, $prev['x'], $prev['y'], $currX, $currY, $color);
                    
                    // Elevation
                    // if (!$this->hideInfo) {
                        // $eCol = $this->darkMode ? ($row['speedKmh'] > 5 ? $this->colors['white'] : $this->colors['red']) : $this->colors['red'];
                        // imageline($this->image, $i * $eleStep, $this->height - ($prev['alt'] / 8), ($i * $eleStep) + 1, $this->height - ($row['alt'] / 8), $eCol);
                    // }
                }
                $prev = ['x' => $currX, 'y' => $currY, 'alt' => $row['alt']];
            }

            // 4. Car Icon & OSD
            if ($prev) {
                $icon = $this->iconCache[$row['CarId']] ?? ($this->iconCache[$row['CarId']] = imagecreatefrompng('resources/'.$row['CarId'].'.png'));
				$sw = imagesx($icon); 
				$sh = imagesy($icon);
                imagecopy($this->image, $icon, $prev['x'] - ($sw/2), $prev['y'] - ($sh/2), 0, 0, $sw, $sh);
                
                if (!$this->hideInfo) {
                
					$this->liveData->processRow(false);
                    $ld = $this->liveData->getData();
					
					$tripText = sprintf("%0.0fkm", $ld[LiveData::MODE_DRIVE]['odoKm']);
					imagettftext($this->image, 24, 0, ($prev['x'] - ($sw / 3)), ($prev['y'] + ($sh * 1.5)), $this->colors['red'], $this->font, $tripText);
					
					// FIXED MASK: %-8s ensures the label and value always occupy the same space
					$mask = "%6s   %-10s   %-10s   %-10s   %-14s   %-14s   %-16s";
					
                    imagefilledrectangle($this->image, 0, 0, $this->width, 55, $this->colors['osd_bg']);
                    
					$px = 25;
					$this->drawMapOsd($px, 48, $this->colors['black'],
					sprintf($mask,
						str_pad(round($row['speedKmh']), 3, "0", STR_PAD_LEFT) . "km/h",
						"Instant: " . str_pad((int)$row['instCon'], 2, "0", STR_PAD_LEFT) . "." . str_pad((int)(((float)$row['instCon'] - (int)$row['instCon']) * 10), 1, "0", STR_PAD_LEFT) . "%",
						"Fuel: " . str_pad(round($row['FuelPct']), 3, "0", STR_PAD_LEFT) . "%",
						"Temp: " . sprintf("%03d", (int)$row['outC']) . "°C",
						"DrvTime: " . formatHourMin($ld[LiveData::MODE_DRIVE]['timeSec']),
						"IdleTime: " . formatHourMin($ld[LiveData::MODE_IDLE]['timeSec']),
						gmdate("Y-m-d H:i", $row["currTime"])
						));
                }

                // Smooth Scroll Viewport
                $lonPx = lonPerPixel($startX, $this->params['zoom']);
                $latPx = latPerPixel($startY, $this->params['zoom']);
                if ($prev['x'] < 600) $this->params['lonCenter'] -= (600 - $prev['x']) * $lonPx;
                if ($prev['x'] > $this->width - 600) $this->params['lonCenter'] += ($prev['x'] - ($this->width - 600)) * $lonPx;
                if ($prev['y'] < 300) $this->params['latCenter'] += (300 - $prev['y']) * $latPx;
                if ($prev['y'] > $this->height - 300) $this->params['latCenter'] -= ($prev['y'] - ($this->height - 300)) * $latPx;
            }

            if (!$this->onlyStaticImage) ob_start();
            imagejpeg($this->image, null, 85);
            if ($this->onlyStaticImage) { fclose($fp); die(); }
            fwrite($fp, ob_get_clean());
            echo "Frame $frame / " . $this->params['keyframes'] . "\r";
        }
        imagedestroy($this->image);
        fclose($fp);
    }
	
	private function drawMapOsd($x, $y, $textColor, $left, $right = " ") {
        $box = imagettfbbox(32, 0, $this->font, $left);
        $textWidth = abs($box[4] - $box[0]);
		imagettftext($this->image, 32, 0, $x, $y, $textColor, $this->font, $left);
        imagettftext($this->image, 32, 0, $x + 16, $y, $textColor, $this->font, $right);
    }
}

$overview = new EvDashboardOverview();
$overview->preprocessData(getStr("filename", "demo_data.json"), (PHP_SAPI !== 'cli'));