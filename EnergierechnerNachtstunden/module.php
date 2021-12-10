<?php

declare(strict_types=1);
    class EnergierechnerNachtstunden extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyString('Periods', '[]');
            $this->RegisterPropertyInteger('consumptionVariableID', 0);

            $this->RegisterVariableFloat('totalCosts', $this->Translate('Total Costs'), '~Euro', 0);
            $this->RegisterVariableFloat('totalConsumption', $this->Translate('Total Consumption'), '~Electricity', 1);

            $this->RegisterPropertyBoolean('Active', true);
            $this->RegisterPropertyBoolean('Daily', false);
            $this->RegisterPropertyBoolean('PreviousDay', false);
            $this->RegisterPropertyBoolean('PreviousWeek', false);
            $this->RegisterPropertyBoolean('CurrentMonth', false);
            $this->RegisterPropertyBoolean('LastMonth', false);

            $this->RegisterPropertyBoolean('Impulse_kWhBool', false);
            $this->RegisterPropertyInteger('Impulse_kWh', 1000);

            $this->RegisterPropertyInteger('UpdateInterval', 600);
            $this->RegisterTimer('ER_UpdateCalculation', 0, 'ER_updateCalculation($_IPS[\'TARGET\']);');

            $this->SetBuffer('Periods', '{}');
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

            $this->MaintainVariable('TodayCosts', $this->Translate('Daily Costs'), 2, '~Euro', 3, $this->ReadPropertyBoolean('Daily') == true);
            $this->MaintainVariable('TodayConsumption', $this->Translate('Daily Consumption'), 2, '~Electricity', 4, $this->ReadPropertyBoolean('Daily') == true);

            $this->MaintainVariable('PreviousDayCosts', $this->Translate('Previous Day Costs'), 2, '~Euro', 5, $this->ReadPropertyBoolean('PreviousDay') == true);
            $this->MaintainVariable('PreviousDayConsumption', $this->Translate('Previous Day Consumption'), 2, '~Electricity', 6, $this->ReadPropertyBoolean('PreviousDay') == true);

            $this->MaintainVariable('PreviousWeekCosts', $this->Translate('Previous Week Costs'), 2, '~Euro', 7, $this->ReadPropertyBoolean('PreviousWeek') == true);
            $this->MaintainVariable('PreviousWeekConsumption', $this->Translate('Previous Week Consumption'), 2, '~Electricity', 8, $this->ReadPropertyBoolean('PreviousWeek') == true);

            $this->MaintainVariable('CurrentMonthCosts', $this->Translate('Current Month Costs'), 2, '~Euro', 9, $this->ReadPropertyBoolean('CurrentMonth') == true);
            $this->MaintainVariable('CurrentMonthConsumption', $this->Translate('Previous Month Consumption'), 2, '~Electricity', 10, $this->ReadPropertyBoolean('CurrentMonth') == true);

            $this->MaintainVariable('LastMonthCosts', $this->Translate('Last Month Costs'), 2, '~Euro', 11, $this->ReadPropertyBoolean('LastMonth') == true);
            $this->MaintainVariable('LastMonthConsumption', $this->Translate('Last Month Consumption'), 2, '~Electricity', 12, $this->ReadPropertyBoolean('LastMonth') == true);

            $periodsList = json_decode($this->ReadPropertyString('Periods'), true);

            $variableIdents = [];

            $periods = [];

            $variablePosition = 50;
            foreach ($periodsList as $key => $period) {
                $startDate = json_decode($period['StartDate'], true);
                $endDate = json_decode($period['EndDate'], true);

                $nightTimeStart = json_decode($period['NightTimeStart'], true);
                $nightTimeEnd = json_decode($period['NightTimeEnd'], true);

                $priceVariableID = $period['PriceVariableID'];
                $dayPrice = GetValue($priceVariableID);
                $nightPrice = $dayPrice; //When no night price is set, use day price

                $priceVariableNightID = 0;
                if (array_key_exists('PriceVariableNightID', $period)) {
                    $priceVariableNightID = $period['PriceVariableNightID'];
                }
                if ($priceVariableNightID != 0) {
                    $nightPrice = GetValue($priceVariableNightID);
                }

                $preiod['startDate'] = $startDate;
                $preiod['endDate'] = $endDate;
                $preiod['dayPrice'] = $dayPrice;
                $preiod['nightPrice'] = $nightPrice;
                $preiod['nightStart'] = $nightTimeStart;
                $preiod['nightEnd'] = $nightTimeEnd;
                array_push($periods, $preiod);

                $variableNameTotalCosts = $this->Translate('Total costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' - ' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variableNameTotalConsumption = $this->Translate('Total consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' - ' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variablePosition++;
                $this->RegisterVariableFloat($identTotalConsumption, $variableNameTotalConsumption, '~Electricity', $variablePosition);
                $variablePosition++;
                $this->RegisterVariableFloat($identTotalCosts, $variableNameTotalCosts, '~Euro', $variablePosition);

                $variableIdents[] = $identTotalCosts;
                $variableIdents[] = $identTotalConsumption;
            }

            $this->SetBuffer('Periods', json_encode($periods));

            //Delete Variables when the entry in the list was deleted
            $childIDs = IPS_GetChildrenIDs($this->InstanceID);
            $variableInstanceIdents = [];
            foreach ($childIDs as $childID) {
                $object = IPS_GetObject($childID);
                if ($object['ObjectType'] == 2) {
                    if ((strpos($object['ObjectIdent'], 'Total_costs_period') === 0) || (strpos($object['ObjectIdent'], 'Total_consumption_period') === 0)) {
                        $variableInstanceIdents[] = $object['ObjectIdent'];
                    }
                }
            }
            foreach ($variableInstanceIdents as $key => $ident) {
                if (!in_array($ident, $variableIdents)) {
                    IPS_DeleteVariable($this->GetIDForIdent($ident));
                    $this->LogMessage('Variable with ident (' . $ident . ') was deleted.', KL_NOTIFY);
                }
            }

            //Register UpdateTimer
            if ($this->ReadPropertyBoolean('Active')) {
                $this->SetTimerInterval('ER_UpdateCalculation', $this->ReadPropertyInteger('UpdateInterval') * 1000);
                $this->updateCalculation();
                $this->SetStatus(102);
            } else {
                $this->SetTimerInterval('ER_UpdateCalculation', 0);
                $this->SetStatus(104);
            }
        }

        public function updateCalculation()
        {
            $totalCosts = 0;
            $totalConsumption = 0;

            if ($this->ReadPropertyBoolean('Daily')) {
                $result = $this->calculate(strtotime('today 00:00'), time());
                $this->SetValue('TodayConsumption', $result['consumption']);
                $this->SetValue('TodayCosts', $result['costs']);
            }
            if ($this->ReadPropertyBoolean('PreviousDay')) {
                $result = $this->calculate(strtotime('yesterday 00:00'), strtotime('yesterday 23:59'));
                $this->SetValue('PreviousDayConsumption', $result['consumption']);
                $this->SetValue('PreviousDayCosts', $result['costs']);
            }

            if ($this->ReadPropertyBoolean('PreviousWeek')) {
                $result = $this->calculate(strtotime('last Monday'), strtotime('next Sunday 23:59:59'));
                $this->SetValue('PreviousWeekConsumption', $result['consumption']);
                $this->SetValue('PreviousWeekCosts', $result['costs']);
            }

            if ($this->ReadPropertyBoolean('CurrentMonth')) {
                $result = $this->calculate(strtotime('midnight first day of this month'), strtotime('last day of this month 23:59:59'));
                $this->SetValue('CurrentMonthConsumption', $result['consumption']);
                $this->SetValue('CurrentMonthCosts', $result['costs']);
            }

            if ($this->ReadPropertyBoolean('CurrentMonth')) {
                $result = $this->calculate(strtotime('midnight first day of this month'), strtotime('last day of this month 23:59:59'));
                $this->SetValue('CurrentMonthConsumption', $result['consumption']);
                $this->SetValue('CurrentMonthCosts', $result['costs']);
            }
            if ($this->ReadPropertyBoolean('LastMonth')) {
                $result = $this->calculate(strtotime('midnight first day of this month - 1 month'), strtotime('last day of this month 23:59:59 -1 month'));
                $this->SetValue('LastMonthConsumption', $result['consumption']);
                $this->SetValue('LastMonthCosts', $result['costs']);
            }

            //Calculate periods and total
            $periods = json_decode($this->ReadPropertyString('Periods'), true);
            foreach ($periods as $key => $period) {
                $startDate = json_decode($period['StartDate'], true);
                $endDate = json_decode($period['EndDate'], true);

                $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $startDate = $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                $endDate = $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];

                $result = $this->calculate(strtotime($startDate), strtotime($endDate));

                $this->SetValue($identTotalConsumption, $result['consumption']);
                $this->SetValue($identTotalCosts, $result['costs']);

                $totalCosts += $result['costs'];
                $totalConsumption += $result['consumption'];
            }
            $this->SetValue('totalConsumption', $totalConsumption);
            $this->SetValue('totalCosts', $totalCosts);
        }

        public function calculate($startDate, $endDate)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $consumptionVariableID = $this->ReadPropertyInteger('consumptionVariableID');
            $consumption = 0;
            $costs = 0;
            $hour = null;

            $values = AC_GetAggregatedValues($archiveID, $consumptionVariableID, 0, $startDate, $endDate, 0);

            $periodBuffer = json_decode($this->GetBuffer('Periods'), true);

            foreach ($periodBuffer as $periodBufferKey => $period) {
                $periodStartDateTimestamp = strtotime($period['startDate']['day'] . '.' . $period['startDate']['month'] . '.' . $period['startDate']['year']);
                $periodEndDateTimeStamp = strtotime($period['endDate']['day'] . '.' . $period['endDate']['month'] . '.' . $period['endDate']['year']);

                foreach ($values as $key => $value) {
                    $tmpValueAVG = $value['Avg'];

                    if ($this->ReadPropertyBoolean('Impulse_kWhBool')) {
                        $tmpValueAVG = $value['Avg'] / $this->ReadPropertyInteger('Impulse_kWh');
                    }

                    $hour = date('H', $value['TimeStamp']) * 1;
                    if (($value['TimeStamp'] >= $periodStartDateTimestamp) && ($value['TimeStamp'] <= $periodEndDateTimeStamp)) {
                        $consumption += $tmpValueAVG;
                        if ((($hour >= $period['nightStart']['hour'])) || (($hour <= $period['nightEnd']['hour']))) {
                            $costs += $tmpValueAVG * $period['nightPrice'];
                        } else {
                            $costs += $tmpValueAVG * $period['dayPrice'];
                        }
                    }
                }
            }
            return ['consumption' => round($consumption, 2), 'costs' => round($costs, 2)];
        }
    }