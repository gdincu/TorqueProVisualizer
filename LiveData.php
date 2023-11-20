<?php

class LiveData {

    const MODE_DRIVE = 1; // >= 10kmh || -15kW and more
	const MODE_IDLE = 3;

    private $liveData;
    private $prevRow;
    private $startRow;
    private $debug;

    /**
     * Live data
     */
    function __construct() {

        $this->initData();
        $this->debug = false;
    }

    /**
     * Init data
     */
    function initData() {
//
        $this->liveData['mode'] = self::MODE_IDLE;
        $this->liveData['modeCounter'] = 0;
        $this->liveData[self::MODE_DRIVE]['timeSec'] = 0;
        $this->liveData[self::MODE_DRIVE]['odoKm'] = 0;
		$this->liveData[self::MODE_IDLE]['timeSec'] = 0;
        $this->liveData['stats'] = array();
//
        $this->prevRow = false;
        $this->startRow = false;
    }

    /**
     * Process row
     */
    function processRow($row) {

        if ($row !== false && ($row['odoKm'] == -1 || $row['instCon'] == -1 ))
            return;

// Detect mode
		$sugMode = self::MODE_IDLE;
        if ($row !== false) {
			if ($row['speedKmh'] > 1) {
                    $sugMode = self::MODE_DRIVE;
                } else {
					$sugMode = self::MODE_IDLE;			
				}
		}

// Evaluate
        if ($this->startRow === false) {
            $this->startRow = $row;
            $this->prevRow = $row;
            $this->liveData['mode'] = $sugMode;
        } else {
            $oldMode = $this->liveData['mode'];
            if ($row === false || $oldMode != $sugMode) {

                if ($row === false || $row['currTime'] - $this->prevRow['currTime'] > 60)
                    $r = $this->prevRow;
                else
                    $r = $row;

                if ($this->debug) {
                    echo "===============\n";
                    echo "time " . ($r["currTime"] - $this->startRow["currTime"]) . "\n";
                    echo "odoKm " . ($r["odoKm"] - $this->startRow["odoKm"]) . "\n";
                }

                $this->liveData[$oldMode]['timeSec'] += round($r['currTime'] - $this->startRow['currTime'], 4);
                if (isset($this->liveData[$oldMode]['odoKm']))
                    $this->liveData[$oldMode]['odoKm'] += round($r['odoKm'] - $this->startRow['odoKm'], 4);

                // Build stats
                if ($oldMode == self::MODE_DRIVE) {
                    $modify = false;
                    if (count($this->liveData['stats']) > 0) {
                        $statsRow = $this->liveData['stats'][count($this->liveData['stats']) - 1];
                        if ($statsRow['mode'] == $oldMode)
                            $modify = true;
                    }
                    //echo ("stop" . ($modify ? "1" : "0") . "\n\n");
                    if (!$modify) {
                        $statsRow = array();
                        $statsRow['mode'] = $oldMode;
                        $statsRow['initTime'] = $this->startRow['currTime'];
                        $statsRow['timeSec'] = $statsRow['odoKm'] = 0;
                        $statsRow['startinstCon'] = $r['instCon'];
                    }
                    $statsRow['endTime'] = $r['currTime'];
                    $statsRow['timeSec'] += round($r['currTime'] - $this->startRow['currTime'], 4);
                    $statsRow['odoKm'] += round($r['odoKm'] - $this->startRow['odoKm'], 4);
                    $statsRow['endinstCon'] = $r['instCon'];
                    $statsRow['lat'] = $r['lat'];
                    $statsRow['lon'] = $r['lon'];
                    if ($modify) {
                        $this->liveData['stats'][count($this->liveData['stats']) - 1] = $statsRow;
                    } else {
                        $this->liveData['stats'][] = $statsRow;
                    }
                }

                $this->liveData['mode'] = $sugMode;
                $this->liveData['modeCounter'] ++;
                $this->startRow = $row;
            }
        }

        // Set
        $this->prevRow = $row;
        if ($row === false && $this->debug) {
            print_r($this->liveData['stats']);
            die("STOP");
        }
    }

    /**
     * Get data
     */
    function getData() {
        return $this->liveData;
    }

}
