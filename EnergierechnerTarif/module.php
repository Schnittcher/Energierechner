<?php

declare(strict_types=1);
    class EnergierechnerTarif extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyString('Periods', '[]');
            $this->RegisterPropertyBoolean('aWATTar', false);
            $this->RegisterAttributeString('aWATTarPrices', '{}');
            $this->RegisterPropertyString('aWATTarStartDate', '{"year": 2021,"month": 1,"day": 1}');

            $this->RegisterPropertyInteger('aWATTarUpdateInterval', 600);
            $this->RegisterTimer('ER_getaWATTarPrice', 0, 'ER_getaWATTarPrice($_IPS[\'TARGET\']);');
        }

        public function Destroy()
        {
            //Never delete this line!
            parent::Destroy();
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();

            if ($this->ReadPropertyBoolean('aWATTar')) {
                $this->getaWATTarPrice();
                //Register UpdateTimer
                $this->SetTimerInterval('ER_getaWATTarPrice', $this->ReadPropertyInteger('aWATTarUpdateInterval') * 1000);
                $this->SetStatus(102);
            } else {
                $this->SetTimerInterval('ER_getaWATTarPrice', 0);
            }
        }

        public function ForwardData($JSONString)
        {
            $this->SendDebug(__FUNCTION__, $JSONString, 0);
            $data = json_decode($JSONString, true);

            switch ($data['Buffer']['Command']) {
                case 'getPeriods':

                    //Nur wenn Preise über aWATTar geholt werden
                    if ($this->ReadPropertyBoolean('aWATTar')) {
                        $aWATTarJSON = $this->ReadAttributeString('aWATTarPrices');
                        $this->SendDebug('Send aWATTar Periods', $aWATTarJSON, 0);
                        return $aWATTarJSON;
                    }

                    $result = json_encode($this->getPeriods());
                    $this->SendDebug('Send Periods', $result, 0);
                    return $result;
                default:
                    $this->LogMessage('Invalid Command', KL_WARNING);
                    break;
            }
            return;
        }

        public function getaWATTarPrice()
        {
            $aWATTarStartDate = json_decode($this->ReadPropertyString('aWATTarStartDate'), true);
            $aWATTarStartDateTimeStamp = strtotime($aWATTarStartDate['day'] . '.' . $aWATTarStartDate['month'] . '.' . $aWATTarStartDate['year']) * 1000;
            $aWATTarEndDateTimeStamp = time() * 1000;
            $periods = [];
            $aWATTar = json_decode(file_get_contents('https://api.awattar.de/v1/marketdata?start=' . $aWATTarStartDateTimeStamp . '&end=' . $aWATTarEndDateTimeStamp), true);
            if (array_key_exists('data', $aWATTar)) {
                foreach ($aWATTar['data'] as $key => $value) {
                    $period['startDate'] = ['day' => 'awattar', 'month' => $value['start_timestamp'] ,'year' => $value['end_timestamp']];
                    $period['startDateTimestamp'] = $value['start_timestamp'];
                    $period['dayPrice'] = $value['marketprice'] * 0.001; //Umrechnung Eur/MWh zu EUR/kWh
                    $period['advancePayment'] = 0; //geht nicht bei aWATTar
                    $period['basePrice'] = 0; //geht nicht bei aWATTar
                    $period['dailyBasePrice'] = 0; //geht nicht bei aWATTar
                    $period['nightPrice'] = 0; //geht nicht bei aWATTar
                    $period['nightStart'] = 0; //geht nicht bei aWATTar
                    $period['nightEnd'] = 0; //geht nicht bei aWATTar
                    $period['periodDays'] = 0; //geht nicht bei aWATTar,
                    array_push($periods, $period);
                }
            }
            //Sort Array ASC
            foreach ($periods as $key => $value) {
                $timestamps[$key] = $value['startDateTimestamp'];
            }
            if (!empty($timestamps)) {
                array_multisort($timestamps, SORT_ASC, $periods);
            }
            $this->WriteAttributeString('aWATTarPrices', json_encode($periods));
        }

        public function getPeriods()
        {
            $periodsList = json_decode($this->ReadPropertyString('Periods'), true);
            $periods = [];
            foreach ($periodsList as $key => $period) {
                $startDate = json_decode($period['StartDate'], true);

                $nightTimeStart = json_decode($period['NightTimeStart'], true);
                $nightTimeEnd = json_decode($period['NightTimeEnd'], true);

                $dayPrice = $period['DayPrice'];
                $advancePayment = $period['AdvancePayment'];
                $basePrice = $period['BasePrice'];
                $nightPrice = $dayPrice; //When no night price is set, use day price

                if ($period['NightPrice'] != 0.00) {
                    $nightPrice = $period['NightPrice'];
                }

                $periodStartDateTimestamp = strtotime($startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year']);

                //Calculate daily Base Price
                $tmpStartDate = new DateTime(date('Y-m-d', $periodStartDateTimestamp));
                $tmpEndDate = new DateTime($startDate['year'] . '-12-31');
                $periodDays = $tmpStartDate->diff($tmpEndDate)->days;

                $period['startDate'] = $startDate;
                $period['startDateTimestamp'] = $periodStartDateTimestamp;
                $period['dayPrice'] = $dayPrice;
                $period['advancePayment'] = $advancePayment;
                $period['basePrice'] = $basePrice;
                $period['dailyBasePrice'] = $basePrice / 365;
                $period['nightPrice'] = $nightPrice;
                $period['nightStart'] = $nightTimeStart;
                $period['nightEnd'] = $nightTimeEnd;
                $period['periodDays'] = $periodDays;
                array_push($periods, $period);
            }

            //Sort Array ASC
            foreach ($periods as $key => $value) {
                $timestamps[$key] = $value['startDateTimestamp'];
            }
            if (!empty($timestamps)) {
                array_multisort($timestamps, SORT_ASC, $periods);
            }
            return $periods;
        }
    }