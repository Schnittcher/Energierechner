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
                $deductionsPerYear = $period['DeductionsPerYear'];
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
                $period['deductionsPerYear'] = $deductionsPerYear;
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