<?php

// Install
// composer require goat1000/svggraph
//  ffmpeg -i test.mjpeg -pix_fmt yuv420p -b:v 4000k -c:v libx264 test.mp4
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

/**
 *
 */
class EvDashboardOverview {

    const TIME_SCREEN_FRAME = 4200; // seconds
    const TIME_SCREEN_SCROLL = 3600; // seconds

    private $jsonData;
    //
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
    // Map
    protected $tileSize = 256;

    /**
     * Init
     */
    function __construct() {

        // convert CLI params to GET
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

        // Init structures
        $this->tileUrl = 'https://b.tile.openstreetmap.org/{z}/{x}/{y}.png';
        $this->tileUrl = 'https://tile-a.openstreetmap.fr/hot/{z}/{x}/{y}.png';
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
        );
    }

    /**
     * Prepare colors
     */
    private function prepareColors() {

        $this->font = 'fonts/RobotoCondensed-Light.ttf';
        $this->font2 = 'fonts/RobotoCondensed-Bold.ttf';
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

    /**
     * Process
     */
    function preprocessData($jsonFileName, $onlyStaticImage = true) {

        $this->onlyStaticImage = $onlyStaticImage;
        $this->fileName = $jsonFileName;
        if (substr(strtolower($this->fileName), -5) != ".json")
            die("JSON file required");

        $data = file_get_contents($this->fileName);
        $data = rtrim(rtrim($data, "\n"), ",");
        $data = "[" . $data . "]";
        $this->jsonData = json_decode($data, true);
        // Prepare data
        $this->params = array(
            "keyframes" => 0,
            "minOdoKm" => -1,
            "maxOdoKm" => -1,
            "minCurrTime" => -1,
            "maxCurrTime" => -1,
            "chargingStartX" => -1,
            "latMin" => -1,
            "latMax" => -1,
            "lonMin" => -1,
            "lonMax" => -1,
            "latStartPoint" => -1,
            "lonStartPoint" => -1,
        );
        foreach ($this->jsonData as $key => &$row) {
            if (isset($row['speedKmhGPS']) && $row['speedKmhGPS'] != -1) {
                $row['speedKmh'] = $row['speedKmhGPS'];
            }

            if (($row['odoKm'] != 1.677721e7) && ($this->params['maxOdoKm'] == -1 || $row['odoKm'] > $this->params['maxOdoKm']))
                $this->params['maxOdoKm'] = $row['odoKm'];
            if ($row['odoKm'] == 1.677721e7)
                $row['odoKm'] = $this->params['maxOdoKm'];
            

            $this->params['keyframes'] ++;
            if ($this->params['minOdoKm'] == -1 || $row['odoKm'] < $this->params['minOdoKm'])
                $this->params['minOdoKm'] = $row['odoKm'];
            if ($this->params['minCurrTime'] == -1 || $row['currTime'] < $this->params['minCurrTime'])
                $this->params['minCurrTime'] = $row['currTime'];
            if ($this->params['maxCurrTime'] == -1 || $row['currTime'] > $this->params['maxCurrTime'])
                $this->params['maxCurrTime'] = $row['currTime'];
            if ($this->params['latMin'] == -1 || ($row['lat'] != -1 && $row['lat'] < $this->params['latMin']))
                $this->params['latMin'] = $row['lat'];
            if ($this->params['latMax'] == -1 || ($row['lat'] != -1 && $row['lat'] > $this->params['latMax']))
                $this->params['latMax'] = $row['lat'];
            if ($this->params['lonMin'] == -1 || ($row['lon'] != -1 && $row['lon'] < $this->params['lonMin']))
                $this->params['lonMin'] = $row['lon'];
            if ($this->params['lonMax'] == -1 || ($row['lon'] != -1 && $row['lon'] > $this->params['lonMax']))
                $this->params['lonMax'] = $row['lon'];
            if ($this->params['latStartPoint'] == -1 && $row['lat'] != -1)
                $this->params['latStartPoint'] = $row['lat'];
            if ($this->params['lonStartPoint'] == -1 && $row['lon'] != -1)
                $this->params['lonStartPoint'] = $row['lon'];
        }
        $this->params['graph0x'] = 400;
        $this->params['graph0y'] = $this->height * 0.66;
        $this->params['xStep'] = ($this->width - $this->params['graph0x'] - 32) / self::TIME_SCREEN_FRAME;
        $this->params['yStep'] = ($this->height - 96) / 200;

        $this->params['latCenter'] = ($this->params['latMax'] - $this->params['latMin']) / 2 + $this->params['latMin'];
        $this->params['lonCenter'] = ($this->params['lonMax'] - $this->params['lonMin']) / 2 + $this->params['lonMin'];
        $this->params['zoom'] = getNum("zoom", 12);

        //print_r($this->params);        die();
        if ($this->params['keyframes'] == 0) {
            die("no keyframes");
        }

        $this->image = imagecreatetruecolor($this->width, $this->height);
        $this->prepareColors();
        if ($this->onlyStaticImage) {
            switch (getNum("m", 1)) {
                // case 0: $this->renderSummary();
                    // break;
                case 1: $this->renderMap();
                    break;
                // case 2: $this->renderChargingGraph();
                    // break;
            }
        } else {
            $this->renderMap();
        }
    }

    /**
     * Render map
     */
    function renderMap() {

        // Fetch map
        $this->imageMapBk = imagecreatetruecolor($this->width, $this->height);
        $this->lastRow = false;
        $this->params['lonCenter'] = $this->params['lonStartPoint'];
        $this->params['latCenter'] = $this->params['latStartPoint'];

        // Render graphs
        if (!$this->onlyStaticImage) {
            $fp = fopen(str_replace(".json", "", $this->fileName) . '_map.mjpeg', 'w');
        } else {
            $fp = fopen(str_replace(".json", "", $this->fileName) . '_map.jpg', 'w');
        }

        $eleStep = $this->width / $this->params['keyframes'];
        $stopFrame = getNum("frame", 0);
        for ($frame = 0; $frame < $this->params['keyframes']; $frame++) {

            if ($this->onlyStaticImage) {
                $frame = $this->params['keyframes'] /* / 10 */;
            }

            // start of render map background
            $this->centerX = lonToTile($this->params['lonCenter'], $this->params['zoom']);
            $this->centerY = latToTile($this->params['latCenter'], $this->params['zoom']);
            $this->offsetX = floor((floor($this->centerX) - $this->centerX) * $this->tileSize);
            $this->offsetY = floor((floor($this->centerY) - $this->centerY) * $this->tileSize);
            $startX = floor($this->centerX - ($this->width / $this->tileSize) / 2);
            $startY = floor($this->centerY - ($this->height / $this->tileSize) / 2);
            $endX = ceil($this->centerX + ($this->width / $this->tileSize) / 2);
            $endY = ceil($this->centerY + ($this->height / $this->tileSize) / 2);
            $this->offsetX = -floor(($this->centerX - floor($this->centerX)) * $this->tileSize);
            $this->offsetY = -floor(($this->centerY - floor($this->centerY)) * $this->tileSize);
            $this->offsetX += floor($this->width / 2);
            $this->offsetY += floor($this->height / 2);
            $this->offsetX += floor($startX - floor($this->centerX)) * $this->tileSize;
            $this->offsetY += floor($startY - floor($this->centerY)) * $this->tileSize;
            $lonPerPixel = lonPerPixel($startX, $this->params['zoom']);
            $latPerPixel = latPerPixel($startY, $this->params['zoom']);
            for ($x = $startX; $x <= $endX; $x++) {
                for ($y = $startY; $y <= $endY; $y++) {
                    $url = str_replace(array('{z}', '{x}', '{y}'), array($this->params['zoom'], $x, $y), $this->tileUrl);
                    $tileData = fetchTile($url);
                    if ($tileData) {
                        $tileImage = imagecreatefromstring($tileData);
                    } else {
                        $tileImage = imagecreate($this->tileSize, $this->tileSize);
                        $color = imagecolorallocate($tileImage, 255, 255, 255);
                        @imagestring($tileImage, 1, 127, 127, 'err', $color);
                    }
                    $destX = ($x - $startX) * $this->tileSize + $this->offsetX;
                    $destY = ($y - $startY) * $this->tileSize + $this->offsetY;
                    imagecopy($this->imageMapBk, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
                }
            }
            // end of render map background

            $this->params['lastOdoKm'] = -1;
            $this->params['lastOdoKmPosX'] = -1;

            imagecopy($this->image, $this->imageMapBk, 0, 0, 0, 0, $this->width, $this->height);
            if ($this->darkMode) {
                imagefilter($this->image, IMG_FILTER_NEGATE);
                $opacity = imagecolorallocatealpha($this->image, 0, 0, 0, 100);
            } else {
                //$opacity = imagecolorallocatealpha($this->image, 255, 255, 255, 50);
                $opacity = imagecolorallocatealpha($this->image, 0, 0, 0, 127);
            }
            imagefilledrectangle($this->image, 0, 0, $this->width, $this->height, $opacity);

            $prevRow = false;
            $cnt = 0;
            $yStep = $this->height / ($this->params['latMax'] - $this->params['latMin']);
            $xStep = $this->width / ($this->params['lonMax'] - $this->params['lonMin']);
            //print_r($this->params);
            //echo "$xStep / $yStep";
            //die();

            $prevRow = false;
            $row = false;
            $cnt = 0;
            $this->liveData->initData();
            foreach ($this->jsonData as $row) {

                $this->liveData->processRow($row);

                if ($row['odoKm'] <= 0 || $row['instCon'] == -1)
                    continue;
                if ($prevRow !== false && ($row['lat'] == -1 || $row['lon'] == -1)) {
                    $row['lat'] = $prevRow['lat'];
                    $row['lon'] = $prevRow['lon'];
                }
                if ($row['lat'] == -1 || $row['lon'] == -1)
                    continue;


                if ($prevRow !== false) {
                    // elevation graph
                    if (!$this->hideInfo) {
                        imagesetthickness($this->image, ($this->darkMode ? 1 : 1));
                        imageline($this->image, $cnt * $eleStep, $this->height - ($prevRow['alt'] / 5), ( $cnt * $eleStep) + 1, $this->height - ($row['alt'] / 5),
                                ($this->darkMode ? ($row['speedKmh'] > 5 ? $this->white : $this->red) : $this->red));
                    }
                    //
                    imagesetthickness($this->image, ($this->darkMode ? 2 : 3));
                    $x0 = floor(($this->width / 2) - $this->tileSize * ( $this->centerX - lonToTile($prevRow['lon'], $this->params['zoom'])));
                    $y0 = floor(($this->height / 2) - $this->tileSize * ($this->centerY - latToTile($prevRow['lat'], $this->params['zoom'])));
                    $x = floor(($this->width / 2) - $this->tileSize * ( $this->centerX - lonToTile($row['lon'], $this->params['zoom'])));
                    $y = floor(($this->height / 2) - $this->tileSize * ($this->centerY - latToTile($row['lat'], $this->params['zoom'])));
                    $i = abs($cnt - $frame) / 5;
                    if ($i > 50)
                        $i = 50;
                    $trackColor = ($this->darkMode ? imagecolorallocatealpha($this->image, 255, 196, 40, $i) : imagecolorallocatealpha($this->image, 0, 128, 40, $i));
                    imageline($this->image, $x0, $y0, $x, $y, $trackColor);
                }

                //
                $prevRow = $row;
                $cnt++;
                if ($cnt > $frame) {
                    break;
                }
            }

            if ($row !== false)
                imagettftext($this->image, 14, 0, ($cnt * $eleStep) + 10, $this->height - 64, $this->black, $this->font, $row['alt'] . "m");
            $this->liveData->processRow(false);

            if ($row !== false) {
                if ($row['odoKm'] != -1 && $row['instCon'] != -1) {
                    $x = floor(($this->width / 2) - $this->tileSize * ( $this->centerX - lonToTile($row['lon'], $this->params['zoom'])));
                    $y = floor(($this->height / 2) - $this->tileSize * ($this->centerY - latToTile($row['lat'], $this->params['zoom'])));
                    $trackColor = ($this->darkMode ? imagecolorallocatealpha($this->image, 255, 196, 40, 0) : imagecolorallocatealpha($this->image, 0, 128, 40, 0));
                    imagefilledellipse($this->image, $x, $y, 12, 12, $trackColor);
					
					//Get Live Data
					$data = $this->liveData->getData();
					
                    imagettftext($this->image, 24, 0, $x + 16, $y + 16, $this->red, $this->font,
                            $this->hideInfo ?
                                    printf("") :
									sprintf("%0.0fkm",$data[LiveData::MODE_DRIVE]['odoKm'])
                                    
                    );
                    // Scroll map
                    if ($x < 650) {
                        $step = abs(650 - $x);
                        $this->params['lonCenter'] -= ($step <= 0 ? 1 : $step) * $lonPerPixel;
                    }
                    if ($x > $this->width - 400) {
                        $step = abs($x - ($this->width - 400));
                        $this->params['lonCenter'] += ($step <= 0 ? 1 : $step) * $lonPerPixel;
                    }
                    if ($y < 400) {
                        $step = abs(400 - $y);
                        $this->params['latCenter'] += ($step <= 0 ? 1 : $step) * $latPerPixel;
                    }
                    if ($y > $this->height - 400) {
                        $step = abs($y - ($this->height - 400));
                        $this->params['latCenter'] -= ($step <= 0 ? 1 : $step) * $latPerPixel;
                    }
                }

                if (!$this->hideInfo) {
                    $opacity = imagecolorallocatealpha($this->image, 0, 0, 0, 72);
                    $textColor = ($this->darkMode ? $this->white : $this->black);
                    
					if (!$this->darkMode)
                        $opacity = imagecolorallocatealpha($this->image, 255, 255, 255, 48);
					
                    imagefilledrectangle($this->image, 0, 0, $this->width, 55, $opacity);
					
					$mask = "%3s%4s   %9s%2s%1s%1s%1s   %6s%4s%1s   %6s%3s%2s   %9s%-6s   %10s%-6s   %16s" ;
					
					$px = 25;
					$this->drawMapOsd($px, 48, $textColor,
					sprintf($mask,
					str_pad(round($row['speedKmh']),3,"0",STR_PAD_LEFT),
					"km/h",
					"Instant: ",
					str_pad((int)$row['instCon'],2,"0",STR_PAD_LEFT),
					".",
					str_pad((int)(((float)$row['instCon']-(int)$row['instCon'])*10),1,"0",STR_PAD_LEFT),
					"%",
					"Fuel: ",
					str_pad(round($row['FuelPct']),3,"0",STR_PAD_LEFT),
					"%",
					"Temp: ",
					str_pad((int)$row['outC'],3,"0",STR_PAD_LEFT),
					"°C",
					"DrvTime: ",
					formatHourMin($data[LiveData::MODE_DRIVE]['timeSec']),
					"IdleTime: ",
					formatHourMin($data[LiveData::MODE_IDLE]['timeSec']),
					gmdate("Y-m-d H:i", $row["currTime"])
					));
                }
            }

            $textColor = ($this->darkMode ? $this->white : $this->black);
            // imagettftext($this->image, 12, 0, 8, $this->height - 8, $textColor, $this->font, 'map © OpenStreetMap, data © OpenStreetMap contributors, © SRTM, Tiles style by Humanitarian OpenStreetMap Team hosted by OpenStreetMap France.');
            if ($this->onlyStaticImage) {
                header('Content-type: image/jpeg');
            } else {
                ob_start();
            }
            imagejpeg($this->image);
            if ($this->onlyStaticImage) {
                die();
            }
            $value = ob_get_contents();
            fwrite($fp, $value);
            ob_end_clean();
            if ($this->speedup > 1)
                $frame += $this->speedup - 1;
        }

        // Free up memory
        fclose($fp);
    }

    /**
     * OSD
     */
    private function drawMapOsd($x, $y, $textColor, $left, $right = " ") {
        $box = imagettfbbox(32, 0, $this->font, $left);
        $textWidth = abs($box[4] - $box[0]);
        // imagettftext($this->image, 32, 0, $x - $textWidth, $y, $textColor, $this->font, $left);
		imagettftext($this->image, 32, 0, $x, $y, $textColor, $this->font, $left);
        imagettftext($this->image, 32, 0, $x + 16, $y, $textColor, $this->font, $right);
    }

}

$overview = new EvDashboardOverview();
$overview->preprocessData(getStr("filename", "demo_data.json"), (PHP_SAPI !== 'cli'));