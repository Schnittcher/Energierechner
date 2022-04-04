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
            $this->RegisterPropertyString('ProfileType', '-');

            $this->RegisterPropertyBoolean('IndividualPeriods', false);
            $this->RegisterPropertyString('IndividualPeriodsList', '[]');

            $this->RegisterPropertyBoolean('Active', false);
            $this->RegisterPropertyBoolean('Balance', false);
            $this->RegisterPropertyBoolean('Daily', false);
            $this->RegisterPropertyBoolean('PreviousDay', false);
            $this->RegisterPropertyBoolean('PreviousWeek', false);
            $this->RegisterPropertyBoolean('CurrentMonth', false);
            $this->RegisterPropertyBoolean('LastMonth', false);
            $this->RegisterPropertyBoolean('CurrentYear', false);

            $this->RegisterPropertyBoolean('PeriodsCalculation', false);
            $this->RegisterPropertyBoolean('NightRate', false);
            $this->RegisterPropertyBoolean('DailyConsumption', false);
            $this->RegisterPropertyBoolean('NightlyConsumption', false);
            $this->RegisterPropertyBoolean('Impulse_kWhBool', false);
            $this->RegisterPropertyInteger('Impulse_kWh', 1000);
            $this->RegisterPropertyBoolean('AddBasePrice', false);

            $this->RegisterPropertyBoolean('MonthlyAggregation', false);
            $this->RegisterPropertyBoolean('WeeklyAggregation', false);
            $this->RegisterPropertyBoolean('YearlyAggregation', false);

            $this->RegisterPropertyInteger('UpdateInterval', 600);
            $this->RegisterTimer('ER_UpdateCalculation', 0, 'ER_updateCalculation($_IPS[\'TARGET\']);');

            $this->SetBuffer('Periods', '{}');
            $this->SetBuffer('DailyBasePrice', 0);

            if (!IPS_VariableProfileExists('ER.Liter')) {
                $this->RegisterProfileFloat('ER.Liter', 'Drops', '', ' Liter', 0, 0, 0.1, 2);
            }

            $this->RegisterMessage($this->InstanceID, FM_CONNECT);
            $this->RegisterMessage($this->InstanceID, KR_READY);
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

            $ProfileType = $this->ReadPropertyString('ProfileType');

            if ($ProfileType == '-') {
                $this->SetStatus(201);
                return;
            }

            $this->getPeriods();

            $this->MaintainVariable('totalCosts', $this->Translate('Total Costs'), 2, '~Euro', 0, $this->ReadPropertyBoolean('PeriodsCalculation') == true);
            $this->MaintainVariable('totalConsumption', $this->Translate('Total Consumption'), 2, $ProfileType, 1, $this->ReadPropertyBoolean('PeriodsCalculation') == true);

            $this->RegisterPeriodsVariables();
            $this->registerIndividualPeriodsVariables();

            $this->MaintainVariable('TodayCosts', $this->Translate('Daily Costs'), 2, '~Euro', 2, $this->ReadPropertyBoolean('Daily') == true);
            $this->MaintainVariable('TodayConsumption', $this->Translate('Daily Consumption'), 2, $ProfileType, 3, $this->ReadPropertyBoolean('Daily') == true);
            $this->MaintainVariable('TodayCostsDaytime', $this->Translate('Daily Costs (daytime)'), 2, '~Euro', 4, $this->ReadPropertyBoolean('Daily') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('TodayConsumptionDaytime', $this->Translate('Daily Consumption (daytime)'), 2, $ProfileType, 5, $this->ReadPropertyBoolean('Daily') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('TodayCostsNighttime', $this->Translate('Daily Costs (nighttime)'), 2, '~Euro', 6, $this->ReadPropertyBoolean('Daily') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);
            $this->MaintainVariable('TodayConsumptionNighttime', $this->Translate('Daily Consumption (nighttime)'), 2, $ProfileType, 7, $this->ReadPropertyBoolean('Daily') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);

            $this->MaintainVariable('PreviousDayCosts', $this->Translate('Previous Day Costs'), 2, '~Euro', 8, $this->ReadPropertyBoolean('PreviousDay') == true);
            $this->MaintainVariable('PreviousDayConsumption', $this->Translate('Previous Day Consumption'), 2, $ProfileType, 9, $this->ReadPropertyBoolean('PreviousDay') == true);
            $this->MaintainVariable('PreviousDayCostsDaytime', $this->Translate('Previous Day Costs (daytime)'), 2, '~Euro', 10, $this->ReadPropertyBoolean('PreviousDay') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('PreviousDayConsumptionDaytime', $this->Translate('Previous Day Consumption (daytime)'), 2, $ProfileType, 11, $this->ReadPropertyBoolean('PreviousDay') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('PreviousDayCostsNighttime', $this->Translate('Previous Day Costs (nighttime)'), 2, '~Euro', 12, $this->ReadPropertyBoolean('PreviousDay') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);
            $this->MaintainVariable('PreviousDayConsumptionNighttime', $this->Translate('Previous Day Consumption (nighttime)'), 2, $ProfileType, 13, $this->ReadPropertyBoolean('PreviousDay') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);

            $this->MaintainVariable('PreviousWeekCosts', $this->Translate('Previous Week Costs'), 2, '~Euro', 14, $this->ReadPropertyBoolean('PreviousWeek') == true);
            $this->MaintainVariable('PreviousWeekConsumption', $this->Translate('Previous Week Consumption'), 2, $ProfileType, 15, $this->ReadPropertyBoolean('PreviousWeek') == true);
            $this->MaintainVariable('PreviousWeekCostsDaytime', $this->Translate('Previous Week Costs (daytime)'), 2, '~Euro', 16, $this->ReadPropertyBoolean('PreviousWeek') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('PreviousWeekConsumptionDaytime', $this->Translate('Previous Week Consumption (daytime)'), 2, $ProfileType, 17, $this->ReadPropertyBoolean('PreviousWeek') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('PreviousWeekCostsNighttime', $this->Translate('Previous Week Costs (nighttime)'), 2, '~Euro', 18, $this->ReadPropertyBoolean('PreviousWeek') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);
            $this->MaintainVariable('PreviousWeekConsumptionNighttime', $this->Translate('Previous Week Consumption (nighttime)'), 2, $ProfileType, 19, $this->ReadPropertyBoolean('PreviousWeek') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);

            $this->MaintainVariable('CurrentMonthCosts', $this->Translate('Current Month Costs'), 2, '~Euro', 20, $this->ReadPropertyBoolean('CurrentMonth') == true);
            $this->MaintainVariable('CurrentMonthConsumption', $this->Translate('Current Month Consumption'), 2, $ProfileType, 21, $this->ReadPropertyBoolean('CurrentMonth') == true);
            $this->MaintainVariable('CurrentMonthCostsDaytime', $this->Translate('Current Month Costs (daytime)'), 2, '~Euro', 22, $this->ReadPropertyBoolean('CurrentMonth') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('CurrentMonthConsumptionDaytime', $this->Translate('Current Month Consumption (daytime)'), 2, $ProfileType, 23, $this->ReadPropertyBoolean('CurrentMonth') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('CurrentMonthCostsNighttime', $this->Translate('Current Month Costs (nighttime)'), 2, '~Euro', 24, $this->ReadPropertyBoolean('CurrentMonth') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);
            $this->MaintainVariable('CurrentMonthConsumptionNighttime', $this->Translate('Current Month Consumption (nighttime)'), 2, $ProfileType, 25, $this->ReadPropertyBoolean('CurrentMonth') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);

            $this->MaintainVariable('LastMonthCosts', $this->Translate('Last Month Costs'), 2, '~Euro', 26, $this->ReadPropertyBoolean('LastMonth') == true);
            $this->MaintainVariable('LastMonthConsumption', $this->Translate('Last Month Consumption'), 2, $ProfileType, 27, $this->ReadPropertyBoolean('LastMonth') == true);
            $this->MaintainVariable('LastMonthCostsDaytime', $this->Translate('Last Month Costs (daytime)'), 2, '~Euro', 28, $this->ReadPropertyBoolean('LastMonth') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('LastMonthConsumptionDaytime', $this->Translate('Last Month Consumption (daytime)'), 2, $ProfileType, 29, $this->ReadPropertyBoolean('LastMonth') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('LastMonthCostsNighttime', $this->Translate('Last Month Costs (nighttime)'), 2, '~Euro', 30, $this->ReadPropertyBoolean('LastMonth') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);
            $this->MaintainVariable('LastMonthConsumptionNighttime', $this->Translate('Last Month Consumption (nighttime)'), 2, $ProfileType, 31, $this->ReadPropertyBoolean('LastMonth') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);

            $this->MaintainVariable('CurrentYearCosts', $this->Translate('Current Year Costs'), 2, '~Euro', 32, $this->ReadPropertyBoolean('CurrentYear') == true);
            $this->MaintainVariable('CurrentYearConsumption', $this->Translate('Current Year Consumption'), 2, $ProfileType, 33, $this->ReadPropertyBoolean('CurrentYear') == true);
            $this->MaintainVariable('CurrentYearCostsDaytime', $this->Translate('Current Year Costs (daytime)'), 2, '~Euro', 34, $this->ReadPropertyBoolean('CurrentYear') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('CurrentYearConsumptionDaytime', $this->Translate('Current Year Consumption (daytime)'), 2, $ProfileType, 35, $this->ReadPropertyBoolean('CurrentYear') == true && $this->ReadPropertyBoolean('DailyConsumption') == true);
            $this->MaintainVariable('CurrentYearCostsNighttime', $this->Translate('Current Year Costs (nighttime)'), 2, '~Euro', 36, $this->ReadPropertyBoolean('CurrentYear') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);
            $this->MaintainVariable('CurrentYearConsumptionNighttime', $this->Translate('Current Year Consumption (nighttime)'), 2, $ProfileType, 37, $this->ReadPropertyBoolean('CurrentYear') == true && $this->ReadPropertyBoolean('NightlyConsumption') == true);

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

        public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
        {
            switch ($Message) {
                case FM_CONNECT:
                case KR_READY:
                    $this->getPeriods();
                    $this->updateCalculation();
                    break;
                default:
                    $this->SendDebug(__FUNCTION__ . ':: Messages from Sender ' . $SenderID, $Message, 0);
                    break;
            }
        }

        public function updateCalculation()
        {
            if ($this->GetBuffer('Periods') == '{}') {
                $this->getPeriods();
            }

            if ($this->ReadPropertyInteger('consumptionVariableID') == 0) {
                return false;
            }

            $aggregationTyp = 0;
            $totalCosts = 0;
            $totalConsumption = 0;

            if ($this->ReadPropertyBoolean('Daily')) {
                $result = $this->calculate(strtotime('today 00:00'), time());
                $this->SetValue('TodayConsumption', $result['consumption']);
                $this->SetValue('TodayCosts', $result['costs']);

                if ($this->ReadPropertyBoolean('DailyConsumption')) {
                    $this->SetValue('TodayConsumptionDaytime', $result['dailyConsumption']);
                    $this->SetValue('TodayCostsDaytime', $result['dailyCosts']);
                }
                if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                    $this->SetValue('TodayConsumptionNighttime', $result['nightlyConsumption']);
                    $this->SetValue('TodayCostsNighttime', $result['nightlyCosts']);
                }
            }
            if ($this->ReadPropertyBoolean('PreviousDay')) {
                $result = $this->calculate(strtotime('yesterday 00:00'), strtotime('yesterday 23:59'));
                $this->SetValue('PreviousDayConsumption', $result['consumption']);
                $this->SetValue('PreviousDayCosts', $result['costs']);

                if ($this->ReadPropertyBoolean('DailyConsumption')) {
                    $this->SetValue('PreviousDayConsumptionDaytime', $result['dailyConsumption']);
                    $this->SetValue('PreviousDayCostsDaytime', $result['dailyCosts']);
                }
                if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                    $this->SetValue('PreviousDayConsumptionNighttime', $result['nightlyConsumption']);
                    $this->SetValue('PreviousDayCostsNighttime', $result['nightlyCosts']);
                }
            }

            if ($this->ReadPropertyBoolean('PreviousWeek')) {
                if ($this->ReadPropertyBoolean('WeeklyAggregation')) {
                    $aggregationTyp = 2;
                }
                $result = $this->calculate(strtotime('last Monday'), strtotime('next Sunday 23:59:59'), $aggregationTyp);
                $this->SetValue('PreviousWeekConsumption', $result['consumption']);
                $this->SetValue('PreviousWeekCosts', $result['costs']);

                if ($this->ReadPropertyBoolean('DailyConsumption')) {
                    $this->SetValue('PreviousWeekConsumptionDaytime', $result['dailyConsumption']);
                    $this->SetValue('PreviousWeekCostsDaytime', $result['dailyCosts']);
                }
                if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                    $this->SetValue('PreviousWeekConsumptionNighttime', $result['nightlyConsumption']);
                    $this->SetValue('PreviousWeekCostsNighttime', $result['nightlyCosts']);
                }
            }

            if ($this->ReadPropertyBoolean('CurrentMonth')) {
                if ($this->ReadPropertyBoolean('MonthlyAggregation')) {
                    $aggregationTyp = 3;
                }
                $result = $this->calculate(strtotime('midnight first day of this month'), strtotime('last day of this month 23:59:59'), $aggregationTyp);
                $this->SetValue('CurrentMonthConsumption', $result['consumption']);
                $this->SetValue('CurrentMonthCosts', $result['costs']);

                if ($this->ReadPropertyBoolean('DailyConsumption')) {
                    $this->SetValue('CurrentMonthConsumptionDaytime', $result['dailyConsumption']);
                    $this->SetValue('CurrentMonthCostsDaytime', $result['dailyCosts']);
                }
                if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                    $this->SetValue('CurrentMonthConsumptionNighttime', $result['nightlyConsumption']);
                    $this->SetValue('CurrentMonthCostsNighttime', $result['nightlyCosts']);
                }
            }
            if ($this->ReadPropertyBoolean('LastMonth')) {
                if ($this->ReadPropertyBoolean('MonthlyAggregation')) {
                    $aggregationTyp = 3;
                }
                $result = $this->calculate(strtotime('midnight first day of this month - 1 month'), strtotime('last day of this month 23:59:59 -1 month'), $aggregationTyp);
                $this->SetValue('LastMonthConsumption', $result['consumption']);
                $this->SetValue('LastMonthCosts', $result['costs']);

                if ($this->ReadPropertyBoolean('DailyConsumption')) {
                    $this->SetValue('LastMonthConsumptionDaytime', $result['dailyConsumption']);
                    $this->SetValue('LastMonthCostsDaytime', $result['dailyCosts']);
                }
                if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                    $this->SetValue('LastMonthConsumptionNighttime', $result['nightlyConsumption']);
                    $this->SetValue('LastMonthCostsNighttime', $result['nightlyCosts']);
                }
            }

            if ($this->ReadPropertyBoolean('CurrentYear')) {
                if ($this->ReadPropertyBoolean('MonthlyAggregation')) {
                    $aggregationTyp = 3;
                }
                $result = $this->calculate(strtotime('midnight first day of january this year'), strtotime('last day of december this year 23:59:59'), $aggregationTyp);
                $this->SetValue('CurrentYearConsumption', $result['consumption']);
                $this->SetValue('CurrentYearCosts', $result['costs']);

                if ($this->ReadPropertyBoolean('DailyConsumption')) {
                    $this->SetValue('CurrentYearConsumptionDaytime', $result['dailyConsumption']);
                    $this->SetValue('CurrentYearCostsDaytime', $result['dailyCosts']);
                }
                if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                    $this->SetValue('CurrentYearConsumptionNighttime', $result['nightlyConsumption']);
                    $this->SetValue('CurrentYearCostsNighttime', $result['nightlyCosts']);
                }
            }

            if ($this->ReadPropertyBoolean('IndividualPeriods')) {
                $individualPeriods = json_decode($this->ReadPropertyString('IndividualPeriodsList'), true);
                foreach ($individualPeriods as $key => $individualPeriod) {
                    $startDate = json_decode($individualPeriod['startDate'], true);
                    $endDate = json_decode($individualPeriod['endDate'], true);

                    $identTotalConsumption = 'Total_consumption_IndividualPeriod' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                    $identTotalCosts = 'Total_costs_period_IndividualPeriod' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                    $StartTimeStamp = strtotime($startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year']);
                    $EndTimeStamp = strtotime($endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year']);

                    $result = $this->calculate($StartTimeStamp, $EndTimeStamp);

                    $this->SetValue($identTotalConsumption, $result['consumption']);
                    $this->SetValue($identTotalCosts, $result['costs']);

                    if ($this->ReadPropertyBoolean('DailyConsumption')) {
                        $identTotalDailyConsumption = 'consumption_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                        $identTotalDailyCosts = 'costs_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                        $this->SetValue($identTotalDailyConsumption, $result['dailyConsumption']);
                        $this->SetValue($identTotalDailyCosts, $result['dailyCosts']);
                    }

                    //Set Nightly Consumption and Costs for Period
                    if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                        $identTotalNightlyConsumption = 'consumption_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                        $identTotalNightlyCosts = 'costs_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                        $this->SetValue($identTotalNightlyConsumption, $result['nightlyConsumption']);
                        $this->SetValue($identTotalNightlyCosts, $result['nightlyCosts']);
                    }
                }
            }

            if ($this->ReadPropertyBoolean('PeriodsCalculation')) {
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

                    $this->SetValue($identTotalConsumption, $result['consumption']);
                    $this->SetValue($identTotalCosts, $result['costs']);

                    //Set Daily Consumption and Costs for Period
                    if ($this->ReadPropertyBoolean('DailyConsumption')) {
                        $identTotalDailyConsumption = 'consumption_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];
                        $identTotalDailyCosts = 'costs_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                        $this->SetValue($identTotalDailyConsumption, $result['dailyConsumption']);
                        $this->SetValue($identTotalDailyCosts, $result['dailyCosts']);
                    }

                    //Set Nightly Consumption and Costs for Period
                    if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                        $identTotalNightlyConsumption = 'consumption_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];
                        $identTotalNightlyCosts = 'costs_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                        $this->SetValue($identTotalNightlyConsumption, $result['nightlyConsumption']);
                        $this->SetValue($identTotalNightlyCosts, $result['nightlyCosts']);
                    }

                    //Balance
                    $variableNameBalance = $this->Translate('Balance period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                    $identBalancePeriod = 'Balance_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];
                    $this->MaintainVariable($identBalancePeriod, $variableNameBalance, 2, '~Euro', 3, $this->ReadPropertyBoolean('Balance') == true);
                    if ($this->ReadPropertyBoolean('Balance')) {
                        $balance = ($period['advancePayment'] * 12) - $result['costs'];
                        $this->SetValue($identBalancePeriod, $balance);
                    }

                    $totalCosts += $result['costs'];
                    $totalConsumption += $result['consumption'];
                    $i++;
                }
                $this->SetValue('totalConsumption', $totalConsumption);
                $this->SetValue('totalCosts', $totalCosts);
            }
        }

        private function calculate($startDate, $endDate, $aggregationTyp = 0)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $consumptionVariableID = $this->ReadPropertyInteger('consumptionVariableID');
            $consumption = 0;
            $dailyConsumption = 0;
            $nightlyConsumption = 0;
            $costs = 0;
            $dailyCosts = 0;
            $nightlyCosts = 0;
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
                $price = $this->getPrice($value['TimeStamp']);
                $costs += $tmpValueAVG * $price['price'];

                if ($this->ReadPropertyBoolean('DailyConsumption')) {
                    if ($price['type'] == 'day') {
                        $dailyConsumption += $tmpValueAVG;
                        $dailyCosts += $tmpValueAVG * $price['price'];
                    }
                }
                if ($this->ReadPropertyBoolean('NightlyConsumption')) {
                    if ($price['type'] == 'night') {
                        $nightlyConsumption += $tmpValueAVG;
                        $nightlyCosts += $tmpValueAVG * $price['price'];
                    }
                }
            }

            //add base price to costs
            if ($this->ReadPropertyBoolean('AddBasePrice')) {
                if ($endDate == 0) {
                    $endDate = time();
                    //$endDate = strtotime('01.01.' . date('Y', $startDate) . ' +1 year');  //no EndDate, set this to next year
                }
                $tmpStartDate = new DateTime(date('Y-m-d', $startDate));
                $tmpEndDate = new DateTime(date('Y-m-d', $endDate));
                $periodDays = $tmpStartDate->diff($tmpEndDate)->days;

                $dailyBasePrice = $this->getDailyBasePrice($startDate);
                $this->SendDebug(__FUNCTION__ . ' :: Daily Base Price', $dailyBasePrice, 0);
                $this->SendDebug(__FUNCTION__ . ' :: Costs without Daily Base Price', $costs, 0);
                if ($periodDays == 0) { //Days cannot be 0
                    $periodDays++;
                }
                $this->SendDebug(__FUNCTION__ . ' :: periodDays', $periodDays, 0);
                $costs += $periodDays * $dailyBasePrice;
                $this->SendDebug(__FUNCTION__ . ' :: Costs with Daily Base Price', $costs, 0);
            }

            return ['consumption' => round($consumption, 2), 'costs' => round($costs, 2), 'dailyConsumption' => round($dailyConsumption, 2), 'dailyCosts' => round($dailyCosts, 2), 'nightlyConsumption' => round($nightlyConsumption, 2), 'nightlyCosts' => round($nightlyCosts, 2)];
        }

        private function getPeriods()
        {
            if (!$this->HasActiveParent()) {
                return;
            }
            $Data['DataID'] = '{ECE0FA26-0A62-7C11-A8D9-F1BFF84AEEB1}';
            $Buffer['Command'] = 'getPeriods';
            $Data['Buffer'] = $Buffer;
            $Data = json_encode($Data);

            $result = $this->SendDataToParent($Data);
            $this->SetBuffer('Periods', $result);

            //return json_decode($result, true);
        }

        private function getPrice($timestamp)
        {
            $price = [];
            $price['price'] = 0;
            $price['type'] = '';

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
                    $price['price'] = $period['dayPrice'];
                    $price['type'] = 'day';
                    if ($this->ReadPropertyBoolean('NightRate') || $this->ReadPropertyBoolean('NightlyConsumption')) {
                        $valueTime = (new DateTime(date('H:i', $timestamp)));
                        $NightTimeStart = new DateTime($period['nightStart']['hour'] . ':' . $period['nightStart']['minute']);
                        $NightTimeEnd = (new DateTime($period['nightEnd']['hour'] . ':' . $period['nightEnd']['minute']));
                        if ($period['nightStart']['hour'] > $period['nightEnd']['hour']) {
                            $NightTimeEnd->modify('+1 day');
                            if (date('H', $timestamp) <= $period['nightStart']['hour']) {
                                $valueTime->modify('+1 day');
                            }
                        }
                        if ($valueTime >= $NightTimeStart && $valueTime <= $NightTimeEnd) {
                            $price['price'] = $period['nightPrice'];
                            $price['type'] = 'night';
                            return $price;
                        } else {
                            return $price; //Dayprice
                        }
                    } else {
                        return $price; //Dayprice
                    }
                }
                //$this->SendDebug(__FUNCTION__ . ':: Outside period (Timestamps)', 'Value: ' . $timestamp . ' Period Start: ' . $periodStartDateTimestamp . ' Period End: ' . $periodEndDateTimeStamp, 0);
            }
            $this->SendDebug(__FUNCTION__ . ' after Fore each (Periods)', 'Value: ' . $timestamp, 0);
            return $price;
        }

        private function getDailyBasePrice($startDate)
        {
            $periods = json_decode($this->GetBuffer('Periods'), true);

            $countPeriods = count($periods) - 1;
            $i = 0;

            foreach ($periods as $periodBufferKey => $period) {
                if ($i >= $countPeriods) {
                    $endTimestamp = time(); //If no Entry in the List
                } else {
                    $endTimestamp = $periods[$i + 1]['startDateTimestamp'];
                }
                if (($startDate >= $period['startDateTimestamp']) && ($startDate < $endTimestamp)) {
                    return $period['dailyBasePrice'];
                }
                $i++;
            }
            return 0;
        }

        private function registerPeriodsVariables()
        {
            $ProfileType = $this->ReadPropertyString('ProfileType');
            $variablePosition = 50;
            if ($this->HasActiveParent()) {
                $periodsList = json_decode($this->GetBuffer('Periods'), true);
                foreach ($periodsList as $key => $period) {
                    $startDate = $period['startDate'];

                    $variableNameTotalCosts = $this->Translate('Total costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                    $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variableNameTotalConsumption = $this->Translate('Total consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                    $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variablePosition++;
                    $this->MaintainVariable($identTotalConsumption, $variableNameTotalConsumption, 2, $ProfileType, $variablePosition, $this->ReadPropertyBoolean('PeriodsCalculation') == true);
                    $variablePosition++;
                    $this->MaintainVariable($identTotalCosts, $variableNameTotalCosts, 2, '~Euro', $variablePosition, $this->ReadPropertyBoolean('PeriodsCalculation') == true);

                    //Variable for Daytime Costs and Consumption for every Period
                    $variableNameTotalDailyCosts = $this->Translate('costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' ' . $this->Translate('(daytime)');
                    $identTotalDailyCosts = 'costs_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variableNameTotalDailyConsumption = $this->Translate('consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' ' . $this->Translate('(daytime)');
                    $identTotalDailyConsumption = 'consumption_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variablePosition++;
                    $this->MaintainVariable($identTotalDailyCosts, $variableNameTotalDailyCosts, 2, '~Euro', $variablePosition, $this->ReadPropertyBoolean('DailyConsumption') == true && $this->ReadPropertyBoolean('PeriodsCalculation') == true);
                    $variablePosition++;
                    $this->MaintainVariable($identTotalDailyConsumption, $variableNameTotalDailyConsumption, 2, $ProfileType, $variablePosition, $this->ReadPropertyBoolean('DailyConsumption') == true && $this->ReadPropertyBoolean('PeriodsCalculation') == true);

                    //Variable for Nighttime Costs and Consumption for every Period
                    $variableNameTotalNightlyCosts = $this->Translate('costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' ' . $this->Translate('(nighttime)');
                    $identTotalNightlyCosts = 'costs_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variableNameTotalNightlyConsumption = $this->Translate('consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . $this->Translate('(nighttime)');
                    $identTotalNightlyConsumption = 'consumption_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'];

                    $variablePosition++;
                    $this->MaintainVariable($identTotalNightlyCosts, $variableNameTotalNightlyCosts, 2, '~Euro', $variablePosition, $this->ReadPropertyBoolean('NightlyConsumption') == true && $this->ReadPropertyBoolean('PeriodsCalculation') == true);
                    $variablePosition++;
                    $this->MaintainVariable($identTotalNightlyConsumption, $variableNameTotalNightlyConsumption, 2, $ProfileType, $variablePosition, $this->ReadPropertyBoolean('NightlyConsumption') == true && $this->ReadPropertyBoolean('PeriodsCalculation') == true);

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
        }

        private function registerIndividualPeriodsVariables()
        {
            $ProfileType = $this->ReadPropertyString('ProfileType');
            $variablePosition = 100;

            $individualPeriods = json_decode($this->ReadPropertyString('IndividualPeriodsList'), true);
            foreach ($individualPeriods as $key => $individualPeriod) {
                $startDate = json_decode($individualPeriod['startDate'], true);
                $endDate = json_decode($individualPeriod['endDate'], true);

                $variableNameTotalCosts = $this->Translate('Total costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . '-' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalCosts = 'Total_costs_period_IndividualPeriod' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variableNameTotalConsumption = $this->Translate('Total consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . '-' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalConsumption = 'Total_consumption_IndividualPeriod' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variablePosition++;
                $this->MaintainVariable($identTotalConsumption, $variableNameTotalConsumption, 2, $ProfileType, $variablePosition, $this->ReadPropertyBoolean('IndividualPeriods') == true);
                $variablePosition++;
                $this->MaintainVariable($identTotalCosts, $variableNameTotalCosts, 2, '~Euro', $variablePosition, $this->ReadPropertyBoolean('IndividualPeriods') == true);

                //Variable for Daytime Costs and Consumption for every Period
                $variableNameTotalDailyCosts = $this->Translate('costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . '-' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'] . ' ' . $this->Translate('(daytime)');
                $identTotalDailyCosts = 'costs_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variableNameTotalDailyConsumption = $this->Translate('consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . '-' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'] . ' ' . $this->Translate('(daytime)');
                $identTotalDailyConsumption = 'consumption_period_daytime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variablePosition++;
                $this->MaintainVariable($identTotalDailyCosts, $variableNameTotalDailyCosts, 2, '~Euro', $variablePosition, $this->ReadPropertyBoolean('DailyConsumption') == true && $this->ReadPropertyBoolean('IndividualPeriods') == true);
                $variablePosition++;
                $this->MaintainVariable($identTotalDailyConsumption, $variableNameTotalDailyConsumption, 2, $ProfileType, $variablePosition, $this->ReadPropertyBoolean('DailyConsumption') == true && $this->ReadPropertyBoolean('IndividualPeriods') == true);

                //Variable for Nighttime Costs and Consumption for every Period
                $variableNameTotalNightlyCosts = $this->Translate('costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . '-' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'] . ' ' . $this->Translate('(nighttime)');
                $identTotalNightlyCosts = 'costs_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variableNameTotalNightlyConsumption = $this->Translate('consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . '-' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'] . ' ' . $this->Translate('(nighttime)');
                $identTotalNightlyConsumption = 'consumption_period_nighttime' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '_' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variablePosition++;
                $this->MaintainVariable($identTotalNightlyCosts, $variableNameTotalNightlyCosts, 2, '~Euro', $variablePosition, $this->ReadPropertyBoolean('NightlyConsumption') == true && $this->ReadPropertyBoolean('IndividualPeriods') == true);
                $variablePosition++;
                $this->MaintainVariable($identTotalNightlyConsumption, $variableNameTotalNightlyConsumption, 2, $ProfileType, $variablePosition, $this->ReadPropertyBoolean('NightlyConsumption') == true && $this->ReadPropertyBoolean('IndividualPeriods') == true);

                $variableIdents[] = $identTotalCosts;
                $variableIdents[] = $identTotalConsumption;
            }
        }
    }