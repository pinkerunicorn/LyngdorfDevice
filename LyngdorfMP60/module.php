<?php

class LyngdorfMP60 extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('HideVariablesWhenOff', false);

        // Receive Buffer für unvollständige TCP Pakete
        $this->SetBuffer('ReceiveBuffer', '');

        // Profile anlegen
        if (!IPS_VariableProfileExists('LYNGDORF.Volume')) {
            IPS_CreateVariableProfile('LYNGDORF.Volume', 2); // Float
            IPS_SetVariableProfileIcon('LYNGDORF.Volume', 'Intensity');
            IPS_SetVariableProfileText('LYNGDORF.Volume', '', ' dB');
            IPS_SetVariableProfileValues('LYNGDORF.Volume', -99.9, 24.0, 0.5);
            IPS_SetVariableProfileDigits('LYNGDORF.Volume', 1);
        }

        if (!IPS_VariableProfileExists('LYNGDORF.Source')) {
            IPS_CreateVariableProfile('LYNGDORF.Source', 1); // Integer
            IPS_SetVariableProfileIcon('LYNGDORF.Source', 'TV');
        }

        if (!IPS_VariableProfileExists('LYNGDORF.AudioMode')) {
            IPS_CreateVariableProfile('LYNGDORF.AudioMode', 1); // Integer
            IPS_SetVariableProfileIcon('LYNGDORF.AudioMode', 'Sound');
        }

        if (!IPS_VariableProfileExists('LYNGDORF.Voicing')) {
            IPS_CreateVariableProfile('LYNGDORF.Voicing', 1); // Integer
            IPS_SetVariableProfileIcon('LYNGDORF.Voicing', 'Speaker');
        }

        // Variablen registrieren
        $this->RegisterVariableBoolean('Power', 'Power', '~Switch', 1);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('Volume', 'Lautstärke', 'LYNGDORF.Volume', 2);
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', 'Mute', '~Switch', 3);
        $this->EnableAction('Mute');

        $this->RegisterVariableInteger('Source', 'Quelle', 'LYNGDORF.Source', 4);
        $this->EnableAction('Source');

        $this->RegisterVariableInteger('AudioMode', 'Audio Mode', 'LYNGDORF.AudioMode', 5);
        $this->EnableAction('AudioMode');

        $this->RegisterVariableInteger('Voicing', 'Voicing', 'LYNGDORF.Voicing', 6);
        $this->EnableAction('Voicing');

        $this->RegisterVariableString('AudioTypeIn', 'Audio Type In', '', 7);
        $this->RegisterVariableString('AudioTypeOut', 'Audio Type Out', '', 8);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

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

    public function RequestAction($Ident, $Value)
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

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if (isset($data->Buffer)) {
            $payload = utf8_decode($data->Buffer);
        } else {
            return;
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
    }

    public function UpdateData()
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

    private function ProcessPacket($packet)
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
            IPS_SetVariableProfileAssociation('LYNGDORF.Source', $index, $name, '', -1);
            $this->SetValue('Source', $index);
        }
        elseif (preg_match('/^SRC\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Source', intval($matches[1]));
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            IPS_SetVariableProfileAssociation('LYNGDORF.AudioMode', $index, $name, '', -1);
            $this->SetValue('AudioMode', $index);
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('AudioMode', intval($matches[1]));
        }
        elseif (preg_match('/^RPVOI\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            IPS_SetVariableProfileAssociation('LYNGDORF.Voicing', $index, $name, '', -1);
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

    private function SendCommand($command)
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        $this->SendDebug('Transmit', $command, 0);
        
        $this->SendDataToParent(json_encode([
            'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
            'Buffer' => utf8_encode($command . "\r")
        ]));
    }

    private function UpdateVisibility($powerState)
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

    private function SetHidden($ident, $hidden)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false) {
            IPS_SetHidden($id, $hidden);
        }
    }
}
