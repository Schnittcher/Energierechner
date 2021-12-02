<?php

declare(strict_types=1);
    class Energierechner extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyString('Prices', '[]');
            $this->RegisterPropertyInteger('consumptionVariableID', 0);
            $this->RegisterPropertyBoolean('enableArchiveForVariables', true);
            $this->RegisterPropertyFloat('PreviousMeterReading', 0);

            $this->RegisterVariableFloat('totalCosts', $this->Translate('Total Costs'), '~Euro');
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

            if ($this->ReadPropertyInteger('consumptionVariableID') != 0) {
                $this->RegisterMessage($this->ReadPropertyInteger('consumptionVariableID'), VM_UPDATE);
            }

            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
            $variableIdents = [];

            foreach ($prices as $key => $value) {
                $startDate = json_decode($value['StartDate'], true);
                $endDate = json_decode($value['EndDate'], true);

                $variableNameTotalCosts = $this->Translate('Total costs period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' - ' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variableNameTotalConsumption = $this->Translate('Total consumption period') . ' ' . $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'] . ' - ' . $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $variableIDTotalCosts = $this->RegisterVariableFloat($identTotalCosts, $variableNameTotalCosts, '~Euro');
                $variableTotalConsumption = $this->RegisterVariableFloat($identTotalConsumption, $variableNameTotalConsumption, '~Electricity');

                if ($this->ReadPropertyBoolean('enableArchiveForVariables')) {
                    AC_SetLoggingStatus($archiveID, $variableIDTotalCosts, true);
                    AC_SetLoggingStatus($archiveID, $variableTotalConsumption, true);
                }

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

        public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
        {
            switch ($Message) {
                case VM_UPDATE:
                    if ($SenderID == $this->ReadPropertyInteger('consumptionVariableID')) {
                        $this->updateCalculation();
                    }
                    break;
                default:
                    $this->LogMessage('Invalid Message ' . $Message, KL_WARNING);
                    break;
            }
        }

        public function updateCalculation()
        {
            $consumptionVariableID = $this->ReadPropertyInteger('consumptionVariableID');

            $prices = json_decode($this->ReadPropertyString('Prices'), true);

            $totalCosts = 0;
            $totalConsumption = 0;

            foreach ($prices as $key => $value) {
                $startDate = json_decode($value['StartDate'], true);
                $endDate = json_decode($value['EndDate'], true);
                $priceVariableID = $value['PriceVariableID'];

                $identTotalConsumption = 'Total_consumption_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];
                $identTotalCosts = 'Total_costs_period' . $startDate['day'] . '_' . $startDate['month'] . '_' . $startDate['year'] . '__' . $endDate['day'] . '_' . $endDate['month'] . '_' . $endDate['year'];

                $price = GetValue($priceVariableID);

                $startDate = $startDate['day'] . '.' . $startDate['month'] . '.' . $startDate['year'];
                $endDate = $endDate['day'] . '.' . $endDate['month'] . '.' . $endDate['year'];
                IPS_LogMessage('test Key',$key);
                IPS_LogMessage('test array_key_first',array_key_first($prices));

                $previousMeterReading = 0;
                if ($key === array_key_first($prices)) {
                    $previousMeterReading = $this->ReadPropertyFloat('PreviousMeterReading');
                    $this->SendDebug('PreviousMeterReading', $previousMeterReading,0);
                }

                $result = $this->calculate(strtotime($startDate), strtotime($endDate), $consumptionVariableID, $price, $previousMeterReading);

                $this->SetValue($identTotalConsumption, $result['consumption']);
                $this->SetValue($identTotalCosts, $result['costs']);

                $totalCosts += $result['costs'];
                $totalConsumption += $result['consumption'];

                $this->SendDebug('Calculation Result', json_encode($result), 0);
            }

            $total = $this->calculate(0, strtotime('now'), $consumptionVariableID, $price);
            $this->SendDebug('Total Calculation Result from Archive', json_encode($total), 0);
            $this->SetValue('totalCosts', $totalCosts);
        }

        public function calculate($startDate, $endDate, $variableID, $costs, $previousMeterReading = 0)
        {
            $archiveID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];

            $this->SendDebug('calculate :: StartDate', date('d.m.Y', $startDate),0);
            $this->SendDebug('calculate :: EndSate', date('d.m.Y', $endDate),0);

            $this->SendDebug('calculate :: PreviousMeterReading', $previousMeterReading,0);
            $previousConsumption = @AC_GetLoggedValues($archiveID, $variableID, 0, $startDate, 1)[0]['Value'] - $previousMeterReading;
            $this->SendDebug('previous Consumption', $previousConsumption, 0);

            $consumption = AC_GetLoggedValues($archiveID, $variableID, $startDate, $endDate, 1)[0]['Value'];

            if ($startDate != 0) {
                $consumption = $consumption - $previousConsumption;
            }

            $costs = $consumption * $costs;

            return ['consumption' => round($consumption, 2), 'costs' => round($costs, 2)];
        }
    }