<?php

declare(strict_types=1);
    class EnergierechnerTarif extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyString('Periods', '[]');
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
        }

        public function ForwardData($JSONString)
        {
            $this->SendDebug(__FUNCTION__, $JSONString, 0);
            $data = json_decode($JSONString, true);

            switch ($data['Buffer']['Command']) {
                case 'getPeriods':
                    $result = json_encode($this->getPeriods());
                    $this->SendDebug('Send Periods', $result, 0);
                    return $result;
                default:
                    $this->LogMessage('Invalid Command', KL_WARNING);
                    break;
            }
            return;
        }

        private function getPeriods()
        {
            $periodsList = json_decode($this->ReadPropertyString('Periods'), true);
            $periods = [];
            foreach ($periodsList as $key => $period) {
                $startDate = json_decode($period['StartDate'], true);
                $endDate = json_decode($period['EndDate'], true);

                $nightTimeStart = json_decode($period['NightTimeStart'], true);
                $nightTimeEnd = json_decode($period['NightTimeEnd'], true);

                $dayPrice = $period['DayPrice'];
                $nightPrice = $dayPrice; //When no night price is set, use day price

                if ($period['NightPrice'] != 0.00) {
                    $nightPrice = $period['NightPrice'];
                }

                $preiod['startDate'] = $startDate;
                $preiod['endDate'] = $endDate;
                $preiod['dayPrice'] = $dayPrice;
                $preiod['nightPrice'] = $nightPrice;
                $preiod['nightStart'] = $nightTimeStart;
                $preiod['nightEnd'] = $nightTimeEnd;
                array_push($periods, $preiod);
            }
            return $periods;
        }
    }