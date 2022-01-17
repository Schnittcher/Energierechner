<?php

declare(strict_types=1);
    class Energierechner extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->ConnectParent('{63472C81-7110-5151-BBE7-DEA310682B31}');
            $this->RegisterPropertyInteger('consumptionVariableID', 0);

            $this->RegisterVariableFloat('totalCosts', $this->Translate('Total Costs'), '~Euro', 0);
            $this->RegisterVariableFloat('totalConsumption', $this->Translate('Total Consumption'), '~Electricity', 1);

            $this->RegisterPropertyBoolean('Active', false);
            $this->RegisterPropertyBoolean('Balance', false);
            $this->RegisterPropertyBoolean('Daily', false);
            $this->RegisterPropertyBoolean('PreviousDay', false);
            $this->RegisterPropertyBoolean('PreviousWeek', false);
            $this->RegisterPropertyBoolean('CurrentMonth', false);
            $this->RegisterPropertyBoolean('LastMonth', false);

            $this->RegisterPropertyBoolean('NightRate', false);
            $this->RegisterPropertyBoolean('Impulse_kWhBool', false);
            $this->RegisterPropertyInteger('Impulse_kWh', 1000);

            $this->RegisterPropertyBoolean('MonthlyAggregation', false);
            $this->RegisterPropertyBoolean('WeeklyAggregation', false);
            $this->RegisterPropertyBoolean('YearlyAggregation', false);

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

            $variableIdents = [];

            if ($this->HasActiveParent()) {
                $this->updateCalculation();
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
            $aggregationTyp = 0;
            $variablePosition = 50;
            $totalCosts = 0;
            $totalConsumption = 0;

            if ($this->HasActiveParent()) {
                $periodsList = $this->getPeriods();
                $this->SetBuffer('Periods', json_encode($periodsList));

                foreach ($periodsList as $key => $period) {
                    $startDate = $period['startDate'];

                    $variableNameTotalCosts = $this->Translate('Total costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                    $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variableNameTotalConsumption = $this->Translate('Total consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                    $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variablePosition++;
                    $this->RegisterVariableFloat($identTotalConsumption, $variableNameTotalConsumption, '~Electricity', $variablePosition);
                    $variablePosition++;
                    $this->RegisterVariableFloat($identTotalCosts, $variableNameTotalCosts, '~Euro', $variablePosition);

                    $variableIdents[] = $identTotalCosts;
                    $variableIdents[] = $identTotalConsumption;
                }

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
            }

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
                if ($this->ReadPropertyBoolean('WeeklyAggregation')) {
                    $aggregationTyp = 2;
                }
                $result = $this->calculate(strtotime('last Monday'), strtotime('next Sunday 23:59:59'), $aggregationTyp);
                $this->SetValue('PreviousWeekConsumption', $result['consumption']);
                $this->SetValue('PreviousWeekCosts', $result['costs']);
            }

            if ($this->ReadPropertyBoolean('CurrentMonth')) {
                if ($this->ReadPropertyBoolean('MonthlyAggregation')) {
                    $aggregationTyp = 3;
                }
                $result = $this->calculate(strtotime('midnight first day of this month'), strtotime('last day of this month 23:59:59'), $aggregationTyp);
                $this->SetValue('CurrentMonthConsumption', $result['consumption']);
                $this->SetValue('CurrentMonthCosts', $result['costs']);
            }
            if ($this->ReadPropertyBoolean('LastMonth')) {
                if ($this->ReadPropertyBoolean('MonthlyAggregation')) {
                    $aggregationTyp = 3;
                }
                $result = $this->calculate(strtotime('midnight first day of this month - 1 month'), strtotime('last day of this month 23:59:59 -1 month'), $aggregationTyp);
                $this->SetValue('LastMonthConsumption', $result['consumption']);
                $this->SetValue('LastMonthCosts', $result['costs']);
            }

            $periods = json_decode($this->GetBuffer('Periods'), true);

            $countPeriods = count($periods) - 1;
            $i = 0;
            if (count($periods) == 0) {
                return;
            }
            foreach ($periods as $key => $period) {
                $startDate = $period['startDate'];

                $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];
                $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                if ($i >= $countPeriods) {
                    $periodEndDateTimeStamp = 0; //If no Entry in the List
                } else {
                    $periodEndDateTimeStamp = strtotime($periods[$i + 1]['startDate']['day'] . '.' . $periods[$i + 1]['startDate']['month'] . '.' . $periods[$i + 1]['startDate']['year']);
                }
                $periodStartDateTimeStamp = strtotime($periods[$i]['startDate']['day'] . '.' . $periods[$i]['startDate']['month'] . '.' . $periods[$i]['startDate']['year']);

                $result = $this->calculate($periodStartDateTimeStamp, $periodEndDateTimeStamp);

                $this->SendDebug(__FUNCTION__ . ' :: Base Price', $period['basePrice'], 0);

                $this->SetValue($identTotalConsumption, $result['consumption']);

                $totalPeriodCosts = ($result['costs'] + $period['basePrice']);
                $this->SetValue($identTotalCosts, $totalPeriodCosts);

                //Balance
                $variableNameBalance = $this->Translate('Balance period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                $identBalancePeriod = 'Balance_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];
                $this->MaintainVariable($identBalancePeriod, $variableNameBalance, 2, '~Euro', 3, $this->ReadPropertyBoolean('Balance') == true);
                if ($this->ReadPropertyBoolean('Balance')) {
                    $balance = ($period['advancePayment'] * 12) - $totalPeriodCosts;
                    $this->SetValue($identBalancePeriod, $balance);
                }

                $totalCosts += $result['costs'];
                $totalConsumption += $result['consumption'];
                $i++;
            }
            $this->SetValue('totalConsumption', $totalConsumption);
            $this->SetValue('totalCosts', $totalCosts);
        }

        private function calculate($startDate, $endDate, $aggregationTyp = 0)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $consumptionVariableID = $this->ReadPropertyInteger('consumptionVariableID');
            $consumption = 0;
            $costs = 0;
            $hour = null;

            $values = AC_GetAggregatedValues($archiveID, $consumptionVariableID, $aggregationTyp, $startDate, $endDate, 0);

            $periodBuffer = json_decode($this->GetBuffer('Periods'), true);

            $this->SendDebug(__FUNCTION__ . ' :: Date', date('d.m.Y - H:i', $startDate) . ' - ' . date('d.m.Y - H:i', $endDate), 0);
            $this->SendDebug(__FUNCTION__ . ' :: Aggregation Typ', $aggregationTyp, 0);
            $this->SendDebug(__FUNCTION__ . ' :: Values', json_encode($values), 0);
            $this->SendDebug(__FUNCTION__ . ' :: Periods', json_encode($periodBuffer), 0);

            $tmpValueAVG = 0;
            foreach ($values as $key => $value) {
                $tmpValueAVG = $value['Avg'];

                if ($this->ReadPropertyBoolean('Impulse_kWhBool')) {
                    $tmpValueAVG = $value['Avg'] / $this->ReadPropertyInteger('Impulse_kWh');
                }
                $consumption += $tmpValueAVG;
                $costs += $tmpValueAVG * $this->getPrice($value['TimeStamp']);
            }
            return ['consumption' => round($consumption, 2), 'costs' => round($costs, 2)];
        }

        private function getPeriods()
        {
            $Data['DataID'] = '{ECE0FA26-0A62-7C11-A8D9-F1BFF84AEEB1}';
            $Buffer['Command'] = 'getPeriods';
            $Data['Buffer'] = $Buffer;
            $Data = json_encode($Data);

            $result = $this->SendDataToParent($Data);

            return json_decode($result, true);
        }

        private function getPrice($timestamp)
        {
            $periods = json_decode($this->GetBuffer('Periods'), true);

            $countPeriods = count($periods) - 1;
            $i = 0;
            foreach ($periods as $periodBufferKey => $period) {
                $periodStartDateTimestamp = strtotime($period['startDate']['day'] . '.' . $period['startDate']['month'] . '.' . $period['startDate']['year']);

                if ($i >= $countPeriods) {
                    $periodEndDateTimeStamp = time(); //If no Entry in the List
                } else {
                    $periodEndDateTimeStamp = strtotime($periods[$i + 1]['startDate']['day'] . '.' . $periods[$i + 1]['startDate']['month'] . '.' . $periods[$i + 1]['startDate']['year']);
                }
                $i++;

                if ($timestamp >= $periodStartDateTimestamp && $timestamp <= $periodEndDateTimeStamp) {
                    if ($this->ReadPropertyBoolean('NightRate')) {
                        $valueTime = (new DateTime(date('H:i', $timestamp)))->modify('+1 day');
                        $NightTimeStart = new DateTime($period['nightStart']['hour'] . ':' . $period['nightStart']['minute']);
                        $NightTimeEnd = (new DateTime($period['nightEnd']['hour'] . ':' . $period['nightEnd']['minute']))->modify('+1 day');

                        if ($valueTime >= $NightTimeStart && $valueTime <= $NightTimeEnd) {
                            return $period['nightPrice'];
                        } else {
                            return $period['dayPrice'];
                        }
                    } else {
                        return $period['dayPrice'];
                    }
                }
            }
        }
    }