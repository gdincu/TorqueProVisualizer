<?php
error_reporting(E_ALL & ~E_STRICT & ~E_DEPRECATED);
ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);
ini_set('display_errors', 'On');
ini_set('memory_limit', '2048M');

if (!extension_loaded("curl")) {
    die("Enable curl extension in your php.ini\n");
}
if (!extension_loaded("gd")) {
    die("Enable gd extension in your php.ini\n");
}

include("global.php");
include("LiveData.php");

class EvDashboardOverview {

    const TIME_SCREEN_FRAME = 4200; // seconds
    const TIME_SCREEN_SCROLL = 3600; // seconds

    private $jsonData;
    private $params;
    private $onlyStaticImage;
    private $image;
    private $imageMapBk;
    private $white;
    private $black;
    private $red;
    private $green;
    private $gridColor;
    private $width = 1920;
    private $height = 1080;
    private $darkMode;
    private $tileUrl;
    private $hideInfo;
    protected $tileSize = 256;
    
    // PERFORMANCE ADDITION: Tile cache and Icon cache
    private $tileCache = [];
    private $iconCache = [];

    function __construct() {
        if (PHP_SAPI === 'cli') {
            if (isset($_SERVER['argc']) > 0) {
                foreach ($_SERVER['argv'] as $key => $value) {
                    if ($key != 0) {
                        $argumentList = explode("&", $value);
                        foreach ($argumentList as $key1 => $value1) {
                            $keyValuePairs = explode("=", $value1);
                            $myKey = $keyValuePairs[0];
                            $myValue = $keyValuePairs[1];
                            $_GET[$myKey] = $myValue;
                            $_REQUEST[$myKey] = $myValue;
                        }
                    }
                }
            }
        }

        $this->tileUrl = 'https://b.tile.openstreetmap.org/{z}/{x}/{y}.png';
        $this->darkMode = getNum("dark", 0);
        $this->hideInfo = (getNum("info", 1) == 0);
        $this->speedup = getNum("speedup", 1);
        $this->liveData = new LiveData();
        $this->fields = array(
            "currTime" => array("title" => "Current time", "unit" => ""),
            "instCon" => array("title" => "Instant", "format" => "%02.0f", "unit" => "%"),
            "speedKmh" => array("title" => "Speed", "format" => "%02.0f", "unit" => "km/h"),
            "odoKm" => array("title" => "Odometer", "format" => "%03.0f", "unit" => "km"),
            "alt" => array("title" => "Altitude", "format" => "%03.0f", "unit" => "m"),
            "outC" => array("title" => "Outdoor temp.", "format" => "%03.1f", "unit" => "°C"),
            "fuelPct" => array("title" => "FuelPct", "format" => "%02.1f", "unit" => "%"),
            "carId" => array("title" => "CarId", "format" => "%s", "unit" => "")
        );
    }

    private function prepareColors() {
        $this->font = __DIR__ . '/fonts/RobotoCondensed-Light.ttf';
        $this->font2 = __DIR__ . '/fonts/RobotoCondensed-Light.ttf';
        $this->white = imagecolorallocate($this->image, 255, 255, 255);
        $this->black = imagecolorallocate($this->image, 0, 0, 0);
        $this->red = imagecolorallocate($this->image, 255, 0, 0);
        $this->green = imagecolorallocate($this->image, 0, 255, 0);
        $this->gridColor = imagecolorallocate($this->image, 24, 24, 24);
        $this->fields['instCon']['color'] = imagecolorallocate($this->image, 255, 192, 16);
        $this->fields['instCon']['color2'] = imagecolorallocatealpha($this->image, 255, 192, 16, 80);
        $this->fields['speedKmh']['color'] = imagecolorallocate($this->image, 0, 255, 255);
        $this->fields['speedKmh']['color2'] = imagecolorallocatealpha($this->image, 0, 255, 255, 80);
    }

    function preprocessData($jsonFileName, $onlyStaticImage = true) {
        $this->onlyStaticImage = $onlyStaticImage;
        $this->fileName = $jsonFileName;
        if (substr(strtolower($this->fileName), -5) != ".json") die("JSON file required");

        $data = file_get_contents($this->fileName);
        $data = rtrim(rtrim($data, "\n"), ",");
        $data = "[" . $data . "]";
        $this->jsonData = json_decode($data, true);

        $this->params = array(
            "keyframes" => 0, "minOdoKm" => -1, "maxOdoKm" => -1, "minCurrTime" => -1,
            "maxCurrTime" => -1, "chargingStartX" => -1, "latMin" => -1, "latMax" => -1,
            "lonMin" => -1, "lonMax" => -1, "latStartPoint" => -1, "lonStartPoint" => -1,
        );

        foreach ($this->jsonData as $key => &$row) {
            if (isset($row['speedKmhGPS']) && $row['speedKmhGPS'] != -1) $row['speedKmh'] = $row['speedKmhGPS'];
            if (($row['odoKm'] != 1.677721e7) && ($this->params['maxOdoKm'] == -1 || $row['odoKm'] > $this->params['maxOdoKm'])) $this->params['maxOdoKm'] = $row['odoKm'];
            if ($row['odoKm'] == 1.677721e7) $row['odoKm'] = $this->params['maxOdoKm'];

            $this->params['keyframes'] ++;
            if ($this->params['minOdoKm'] == -1 || $row['odoKm'] < $this->params['minOdoKm']) $this->params['minOdoKm'] = $row['odoKm'];
            if ($this->params['minCurrTime'] == -1 || $row['currTime'] < $this->params['minCurrTime']) $this->params['minCurrTime'] = $row['currTime'];
            if ($this->params['maxCurrTime'] == -1 || $row['currTime'] > $this->params['maxCurrTime']) $this->params['maxCurrTime'] = $row['currTime'];
            if ($this->params['latMin'] == -1 || ($row['lat'] != -1 && $row['lat'] < $this->params['latMin'])) $this->params['latMin'] = $row['lat'];
            if ($this->params['latMax'] == -1 || ($row['lat'] != -1 && $row['lat'] > $this->params['latMax'])) $this->params['latMax'] = $row['lat'];
            if ($this->params['lonMin'] == -1 || ($row['lon'] != -1 && $row['lon'] < $this->params['lonMin'])) $this->params['lonMin'] = $row['lon'];
            if ($this->params['lonMax'] == -1 || ($row['lon'] != -1 && $row['lon'] > $this->params['lonMax'])) $this->params['lonMax'] = $row['lon'];
            if ($this->params['latStartPoint'] == -1 && $row['lat'] != -1) $this->params['latStartPoint'] = $row['lat'];
            if ($this->params['lonStartPoint'] == -1 && $row['lon'] != -1) $this->params['lonStartPoint'] = $row['lon'];
        }

        $this->width = (getNum("res", "") == "4K") ? 3840 : 1920;
        $this->height = (getNum("res", "") == "4K") ? 2160 : 1080;

        $this->params['latCenter'] = $this->params['latStartPoint'];
        $this->params['lonCenter'] = $this->params['lonStartPoint'];
        $this->params['zoom'] = getNum("zoom", 12);

        $this->image = imagecreatetruecolor($this->width, $this->height);
        $this->imageMapBk = imagecreatetruecolor($this->width, $this->height);
        $this->prepareColors();
        $this->renderMap();
    }

    // PERFORMANCE ADDITION: Helper to get and cache tile resources
    private function getTileCached($x, $y, $z) {
        $key = "{$z}_{$x}_{$y}";
        if (isset($this->tileCache[$key])) return $this->tileCache[$key];
        
        $url = str_replace(array('{z}', '{x}', '{y}'), array($z, $x, $y), $this->tileUrl);
        $tileData = fetchTile($url);
        if ($tileData) {
            $this->tileCache[$key] = imagecreatefromstring($tileData);
        } else {
            $this->tileCache[$key] = imagecreate($this->tileSize, $this->tileSize);
            imagecolorallocate($this->tileCache[$key], 255, 255, 255);
        }
        return $this->tileCache[$key];
    }

    function renderMap() {
        $outputFile = str_replace(".json", "", $this->fileName) . ($this->onlyStaticImage ? '_map.jpg' : '_map.mjpeg');
        $fp = fopen($outputFile, 'wb');

        $eleStep = $this->width / $this->params['keyframes'];

        for ($frame = 0; $frame < $this->params['keyframes']; $frame += $this->speedup) {
            if ($this->onlyStaticImage) $frame = $this->params['keyframes'] - 1;

            // 1. Calculate Tile Viewport
            $centerX = lonToTile($this->params['lonCenter'], $this->params['zoom']);
            $centerY = latToTile($this->params['latCenter'], $this->params['zoom']);
            
            $startX = floor($centerX - ($this->width / $this->tileSize) / 2);
            $startY = floor($centerY - ($this->height / $this->tileSize) / 2);
            $endX = ceil($centerX + ($this->width / $this->tileSize) / 2);
            $endY = ceil($centerY + ($this->height / $this->tileSize) / 2);

            $offsetX = -floor(($centerX - floor($centerX)) * $this->tileSize) + floor($this->width / 2) + floor($startX - floor($centerX)) * $this->tileSize;
            $offsetY = -floor(($centerY - floor($centerY)) * $this->tileSize) + floor($this->height / 2) + floor($startY - floor($centerY)) * $this->tileSize;

            $lonPerPixel = lonPerPixel($startX, $this->params['zoom']);
            $latPerPixel = latPerPixel($startY, $this->params['zoom']);

            // 2. Optimized Tile Copy (No re-downloads if cached)
            for ($x = $startX; $x <= $endX; $x++) {
                for ($y = $startY; $y <= $endY; $y++) {
                    $tileImage = $this->getTileCached($x, $y, $this->params['zoom']);
                    $destX = ($x - $startX) * $this->tileSize + $offsetX;
                    $destY = ($y - $startY) * $this->tileSize + $offsetY;
                    imagecopy($this->imageMapBk, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
                }
            }

            imagecopy($this->image, $this->imageMapBk, 0, 0, 0, 0, $this->width, $this->height);
            
            // Dark Mode Logic
            if ($this->darkMode) {
                imagefilter($this->image, IMG_FILTER_NEGATE);
                $opacity = imagecolorallocatealpha($this->image, 0, 0, 0, 100);
            } else {
                $opacity = imagecolorallocatealpha($this->image, 0, 0, 0, 127);
            }
            imagefilledrectangle($this->image, 0, 0, $this->width, $this->height, $opacity);

            // 3. Draw Tracks (Original Logic)
            $prevRow = false;
            $cnt = 0;
            $this->liveData->initData();
            foreach ($this->jsonData as $row) {
                $this->liveData->processRow($row);
                if ($row['lat'] == -1 || $row['lon'] == -1) continue;

                if ($prevRow !== false) {
                    // Elevation
                    if (!$this->hideInfo) {
                        imagesetthickness($this->image, ($this->darkMode ? 1 : 1));
                        imageline($this->image, $cnt * $eleStep, $this->height - ($prevRow['alt'] / 8), ( $cnt * $eleStep) + 1, $this->height - ($row['alt'] / 8),
                                ($this->darkMode ? ($row['speedKmh'] > 5 ? $this->white : $this->red) : $this->red));
                    }
                    // Map Track
                    imagesetthickness($this->image, ($this->darkMode ? 2 : 3));
                    $x0 = floor(($this->width / 2) - $this->tileSize * ($centerX - lonToTile($prevRow['lon'], $this->params['zoom'])));
                    $y0 = floor(($this->height / 2) - $this->tileSize * ($centerY - latToTile($prevRow['lat'], $this->params['zoom'])));
                    $x = floor(($this->width / 2) - $this->tileSize * ($centerX - lonToTile($row['lon'], $this->params['zoom'])));
                    $y = floor(($this->height / 2) - $this->tileSize * ($centerY - latToTile($row['lat'], $this->params['zoom'])));
                    
                    $trackColor = (strpos($row['CarId'], '_r') !== false) ? imagecolorallocate($this->image, 255, 120, 120) : imagecolorallocate($this->image, 0, 204, 102);
                    imageline($this->image, $x0, $y0, $x, $y, $trackColor);
                }
                $prevRow = $row;
                $cnt++;
                if ($cnt > $frame) break;
            }
			
			// Elevation numerical value at the bottom
            if ($row !== false && !$this->hideInfo) {
                // We use $this->red and offset it slightly from the current 'cnt' position
                imagettftext($this->image, 14, 0, ($cnt * $eleStep) + 10, $this->height - 64, $this->red, $this->font, $row['alt'] . "m");
            }

            // 4. Car Icon and Scrolling Logic
            if ($row !== false) {
                $iconKey = $row['CarId'];
                if (!isset($this->iconCache[$iconKey])) {
                    $this->iconCache[$iconKey] = imagecreatefrompng('resources/' . $iconKey . '.png');
                }
                $smallImage = $this->iconCache[$iconKey];
                $sw = imagesx($smallImage); $sh = imagesy($smallImage);
                imagecopy($this->image, $smallImage, ($x - ($sw / 2)), ($y - ($sh / 2)), 0, 0, $sw, $sh);

				$this->liveData->processRow(false); 
				$data = $this->liveData->getData();
				
                imagettftext($this->image, 24, 0, ($x - ($sw / 3)), ($y + ($sh * 1.5)), $this->red, $this->font,$this->hideInfo ? "" : sprintf("%0.0fkm", $data[LiveData::MODE_DRIVE]['odoKm']));

                // Scroll boundaries
                if ($x < 650) $this->params['lonCenter'] -= abs(650 - $x) * $lonPerPixel;
                if ($x > $this->width - 400) $this->params['lonCenter'] += abs($x - ($this->width - 400)) * $lonPerPixel;
                if ($y < 400) $this->params['latCenter'] += abs(400 - $y) * $latPerPixel;
                if ($y > $this->height - 400) $this->params['latCenter'] -= abs($y - ($this->height - 400)) * $latPerPixel;
            }

            // 5. OSD Overlay
            if (!$this->hideInfo) {
    // Background
    $opacity = imagecolorallocatealpha($this->image, $this->darkMode ? 0 : 255, $this->darkMode ? 0 : 255, $this->darkMode ? 0 : 255, $this->darkMode ? 72 : 48);
    imagefilledrectangle($this->image, 0, 0, $this->width, 55, $opacity);

    // FIXED MASK: %-8s ensures the label and value always occupy the same space
    $mask = "%6s   %-10s   %-10s   %-10s   %-14s   %-14s   %-16s";
    
    $osdString = sprintf($mask,
						str_pad(round($row['speedKmh']), 3, "0", STR_PAD_LEFT) . "km/h",
						"Instant: " . str_pad((int)$row['instCon'], 2, "0", STR_PAD_LEFT) . "." . str_pad((int)(((float)$row['instCon'] - (int)$row['instCon']) * 10), 1, "0", STR_PAD_LEFT) . "%",
						"Fuel: " . str_pad(round($row['FuelPct']), 3, "0", STR_PAD_LEFT) . "%",
						"Temp: " . sprintf("%03d", (int)$row['outC']) . "°C",
						"DrvTime: " . formatHourMin($data[LiveData::MODE_DRIVE]['timeSec']),
						"IdleTime: " . formatHourMin($data[LiveData::MODE_IDLE]['timeSec']),
						gmdate("Y-m-d H:i", $row["currTime"])
						);

    $this->drawMapOsd(25, 48, ($this->darkMode ? $this->white : $this->black), $osdString);
}

            // Output frame
            if (!$this->onlyStaticImage) ob_start();
            imagejpeg($this->image, null, 85);
            if ($this->onlyStaticImage) die();
            fwrite($fp, ob_get_clean());

            if ($frame % 50 == 0) echo "Rendering: Frame $frame / " . $this->params['keyframes'] . "\r";
        }
        fclose($fp);
    }

    private function drawMapOsd($x, $y, $textColor, $left) {
        imagettftext($this->image, 32, 0, $x, $y, $textColor, $this->font, $left);
    }
}

$overview = new EvDashboardOverview();
$overview->preprocessData(getStr("filename", "demo_data.json"), (PHP_SAPI !== 'cli'));