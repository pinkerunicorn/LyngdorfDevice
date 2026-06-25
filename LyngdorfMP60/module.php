<?php

declare(strict_types=1);

class LyngdorfMP60 extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('HideVariablesWhenOff', false);

        // Receive Buffer für unvollständige TCP Pakete
        $this->SetBuffer('ReceiveBuffer', '');

        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', 'Power', '~Switch', 1);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('Volume', 'Lautstärke', '', 2);
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', 'Mute', '~Switch', 3);
        $this->EnableAction('Mute');

        $this->RegisterVariableInteger('Source', 'Quelle', '', 4);
        $this->EnableAction('Source');

        $this->RegisterVariableInteger('AudioMode', 'Audio Mode', '', 5);
        $this->EnableAction('AudioMode');

        $this->RegisterVariableInteger('Voicing', 'Voicing', '', 6);
        $this->EnableAction('Voicing');

        $this->RegisterVariableString('AudioTypeIn', 'Audio Type In', '', 7);
        $this->RegisterVariableString('AudioTypeOut', 'Audio Type Out', '', 8);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (function_exists('IPS_SetVariableCustomPresentation')) {


            IPS_SetVariableCustomPresentation($this->GetIDForIdent('Volume'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'ICON'         => 'Intensity',
                'SUFFIX'       => ' dB',
                'MIN'          => -99.9,
                'MAX'          => 24.0,
                'STEP'         => 0.5
            ]);
            
            // Initialization for enumerations if not set yet
            $this->InitializeEnumeration('Source', 'TV');
            $this->InitializeEnumeration('AudioMode', 'Sound');
            $this->InitializeEnumeration('Voicing', 'Speaker');
        }

        if ($this->HasActiveParent()) {
            $this->SendCommand('!VERB(1)');
            $this->SendCommand('!POWER?');
            $this->SendCommand('!VOL?');
            $this->SendCommand('!MUTE?');
            $this->SendCommand('!SRCS?');
            $this->SendCommand('!AUDMODEL?');
            $this->SendCommand('!RPVOIS?');
            $this->SendCommand('!SRC?');
            $this->SendCommand('!AUDMODE?');
            $this->SendCommand('!RPVOI?');
            $this->SendCommand('!AUDTYPE?');
            $this->SendCommand('!AUDTYPEOUT?');
        }
    }

    public function RequestAction(string $Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->SendCommand('!POWERONMAIN');
                } else {
                    $this->SendCommand('!POWEROFFMAIN');
                }
                break;

            case 'Volume':
                $volInt = (int)round($Value * 10);
                $this->SendCommand('!VOL(' . $volInt . ')');
                break;

            case 'Mute':
                if ($Value) {
                    $this->SendCommand('!MUTEON');
                } else {
                    $this->SendCommand('!MUTEOFF');
                }
                break;

            case 'Source':
                $this->SendCommand('!SRC(' . $Value . ')');
                break;

            case 'AudioMode':
                $this->SendCommand('!AUDMODE(' . $Value . ')');
                break;

            case 'Voicing':
                $this->SendCommand('!RPVOI(' . $Value . ')');
                break;
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString);
        if (isset($data->Buffer)) {
            $payload = is_string($data->Buffer) ? $data->Buffer : '';
        } else {
            return "";
        }
        
        $buffer = $this->GetBuffer('ReceiveBuffer');
        $buffer .= $payload;

        $packets = explode("\r", $buffer);
        $this->SetBuffer('ReceiveBuffer', array_pop($packets));

        foreach ($packets as $packet) {
            $packet = trim($packet);
            if (!empty($packet)) {
                $this->ProcessPacket($packet);
            }
        }
        return "";
    }

    public function UpdateData(): void
    {
        if ($this->HasActiveParent()) {
            $this->SendCommand('!VERB(1)');
            $this->SendCommand('!POWER?');
            $this->SendCommand('!VOL?');
            $this->SendCommand('!MUTE?');
            $this->SendCommand('!SRCS?');
            $this->SendCommand('!AUDMODEL?');
            $this->SendCommand('!RPVOIS?');
            $this->SendCommand('!SRC?');
            $this->SendCommand('!AUDMODE?');
            $this->SendCommand('!RPVOI?');
            $this->SendCommand('!AUDTYPE?');
            $this->SendCommand('!AUDTYPEOUT?');
        }
    }

    private function ProcessPacket(string $packet): void
    {
        if (strpos($packet, '!') !== 0 && strpos($packet, '#') !== 0) {
            return;
        }

        $command = substr($packet, 1);

        $this->SendDebug('Receive', $command, 0);

        if (preg_match('/^POWER\((\d)\)$/', $command, $matches)) {
            $power = ($matches[1] == '1');
            $this->SetValue('Power', $power);
            $this->UpdateVisibility($power);
        } 
        elseif (preg_match('/^VOL\((-?\d+)\)$/', $command, $matches)) {
            $this->SetValue('Volume', floatval($matches[1]) / 10);
        }
        elseif ($command === 'MUTEON') {
            $this->SetValue('Mute', true);
        }
        elseif ($command === 'MUTEOFF') {
            $this->SetValue('Mute', false);
        }
        elseif (preg_match('/^SRC\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateEnumerationOption('Source', $index, $name);
            $this->SetValue('Source', $index);
        }
        elseif (preg_match('/^SRC\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Source', intval($matches[1]));
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateEnumerationOption('AudioMode', $index, $name);
            $this->SetValue('AudioMode', $index);
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('AudioMode', intval($matches[1]));
        }
        elseif (preg_match('/^RPVOI\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            $this->UpdateEnumerationOption('Voicing', $index, $name);
            $this->SetValue('Voicing', $index);
        }
        elseif (preg_match('/^RPVOI\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Voicing', intval($matches[1]));
        }
        elseif (preg_match('/^AUDTYPE\((.*)\)$/', $command, $matches)) {
            $this->SetValue('AudioTypeIn', $matches[1]);
        }
        elseif (preg_match('/^AUDTYPEOUT\((.*)\)$/', $command, $matches)) {
            $this->SetValue('AudioTypeOut', $matches[1]);
        }
    }

    private function SendCommand(string $command): void
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        $this->SendDebug('Transmit', $command, 0);
        
        $this->SendDataToParent(json_encode([
            'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
            'Buffer' => $command . "\r"
        ]));
    }

    private function UpdateVisibility(bool $powerState): void
    {
        if (!$this->ReadPropertyBoolean('HideVariablesWhenOff')) {
            $this->SetHidden('Volume', false);
            $this->SetHidden('Mute', false);
            $this->SetHidden('Source', false);
            $this->SetHidden('AudioMode', false);
            $this->SetHidden('Voicing', false);
            $this->SetHidden('AudioTypeIn', false);
            $this->SetHidden('AudioTypeOut', false);
            return;
        }

        $hidden = !$powerState;
        $this->SetHidden('Volume', $hidden);
        $this->SetHidden('Mute', $hidden);
        $this->SetHidden('Source', $hidden);
        $this->SetHidden('AudioMode', $hidden);
        $this->SetHidden('Voicing', $hidden);
        $this->SetHidden('AudioTypeIn', $hidden);
        $this->SetHidden('AudioTypeOut', $hidden);
    }

    private function SetHidden(string $ident, bool $hidden): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false) {
            IPS_SetHidden($id, $hidden);
        }
    }

    private function InitializeEnumeration(string $ident, string $icon): void
    {
        $id = $this->GetIDForIdent($ident);
        $pres = IPS_GetVariableCustomPresentation($id);
        if (!is_array($pres) || empty($pres) || !isset($pres['PRESENTATION'])) {
            IPS_SetVariableCustomPresentation($id, [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'ICON'         => $icon,
                'Options'      => []
            ]);
        }
    }

    private function UpdateEnumerationOption(string $ident, int $value, string $label): void
    {
        if (!function_exists('IPS_GetVariableCustomPresentation')) return;
        $id = $this->GetIDForIdent($ident);
        $pres = IPS_GetVariableCustomPresentation($id);
        if (!is_array($pres)) {
            $pres = ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'Options' => []];
        }
        if (!isset($pres['Options']) || !is_array($pres['Options'])) {
            $pres['Options'] = [];
        }

        $found = false;
        foreach ($pres['Options'] as &$option) {
            if (isset($option['Value']) && $option['Value'] === $value) {
                $option['Label'] = $label;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $pres['Options'][] = [
                'Value' => $value,
                'Label' => $label
            ];
            // Options sortieren (optional, nach Value)
            usort($pres['Options'], function($a, $b) {
                return $a['Value'] <=> $b['Value'];
            });
        }
        IPS_SetVariableCustomPresentation($id, $pres);
    }
}
