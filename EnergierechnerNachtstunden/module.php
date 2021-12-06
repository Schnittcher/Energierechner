<?php

declare(strict_types=1);
    class EnergierechnerNachtstunden extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyString('Prices', '[]');
            $this->RegisterPropertyInteger('consumptionVariableID', 0);

            $this->RegisterVariableFloat('totalCosts', $this->Translate('Total Costs'), '~Euro');
            $this->RegisterVariableFloat('totalConsumption', $this->Translate('Total Consumption'), '~Electricity');
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

            $prices = json_decode($this->ReadPropertyString('Prices'), true);

            $variableIdents = [];

            foreach ($prices as $key => $value) {
                $startDate = json_decode($value['StartDate'], true);
                $endDate = json_decode($value['EndDate'], true);

                $variableNameTotalCosts = $this->Translate('Total costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' - ' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variableNameTotalConsumption = $this->Translate('Total consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' - ' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $this->RegisterVariableFloat($identTotalCosts, $variableNameTotalCosts, '~Euro', 2);
                $this->RegisterVariableFloat($identTotalConsumption, $variableNameTotalConsumption, '~Electricity', 2);

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

        public function updateCalculation()
        {
            $consumptionVariableID = $this->ReadPropertyInteger('consumptionVariableID');

            $prices = json_decode($this->ReadPropertyString('Prices'), true);

            $totalCosts = 0;
            $totalConsumption = 0;

            $nightPrice = 0;
            $nightTimeStart = 0;
            $nightTimeEnd = 0;

            foreach ($prices as $key => $value) {
                $startDate = json_decode($value['StartDate'], true);
                $endDate = json_decode($value['EndDate'], true);

                if (array_key_exists('NightTimeStart', $value) && (array_key_exists('NightTimeEnd', $value))) {
                    if (($value['NightTimeStart'] != null) && ($value['NightTimeEnd'] != null)) {
                        $nightTimeStart = json_decode($value['NightTimeStart'], true);
                        $nightTimeEnd = json_decode($value['NightTimeEnd'], true);

                        $nightTimeStartString = implode(':', $nightTimeStart);
                        $nightTimeEndString = implode(':', $nightTimeEnd);

                        $this->SendDebug(__FUNCTION__ . ':: Night Time', $nightTimeStartString . ' - ' . $nightTimeEndString, 0);
                    }
                }

                $priceVariableID = $value['PriceVariableID'];
                $dayPrice = GetValue($priceVariableID);

                $priceVariableNightID = 0;
                if (array_key_exists('PriceVariableNightID', $value)) {
                    $priceVariableNightID = $value['PriceVariableNightID'];
                }

                if ($priceVariableNightID != 0) {
                    $nightPrice = GetValue($priceVariableNightID);
                } else {
                    $nightPrice = $dayPrice;
                }

                $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $startDate = $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                $endDate = $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];

                $result = $this->calculate(strtotime($startDate), strtotime($endDate), $consumptionVariableID, $dayPrice, $nightPrice, $nightTimeStart, $nightTimeEnd);

                $this->SetValue($identTotalConsumption, $result['consumption']);
                $this->SetValue($identTotalCosts, $result['costs']);

                $totalCosts += $result['costs'];
                $totalConsumption += $result['consumption'];

                $this->SendDebug('Calculation Result', json_encode($result), 0);
            }

            $this->SetValue('totalConsumption', $totalConsumption);
            $this->SetValue('totalCosts', $totalCosts);
        }

        private function calculate($startDate, $endDate, $variableID, $dayPrice, $nightPrice, $nightTimeStart, $nightTimeEnd)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

            $consumption = 0;
            $costs = 0;
            $hour = 0;

            $values = AC_GetAggregatedValues($archiveID, $variableID, 0, $startDate, $endDate, 0);

            foreach ($values as $key => $value) {
                $consumption += $value['Avg'];
                $hour = date('H', $value['TimeStamp']) * 1;
                //$this->SendDebug(__FUNCTION__ . ':: Hours: V/S/E', $hour . ' / ' . $nightTimeStart['hour'] . ' / ' . $nightTimeEnd['hour'], 0);

                if ((($hour >= $nightTimeStart['hour'])) || (($hour <= $nightTimeEnd['hour']))) {
                    $this->SendDebug(__FUNCTION__ . ':: Calculate with Night Price (Hour: ' . $hour . ')', $value['Avg'] . ' * ' . $nightPrice, 0);
                    $costs += $value['Avg'] * $nightPrice;
                } else {
                    $costs += $value['Avg'] * $dayPrice;
                }
            }

            return ['consumption' => round($consumption, 2), 'costs' => round($costs, 2)];
        }
    }