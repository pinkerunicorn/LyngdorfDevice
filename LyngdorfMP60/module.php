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
        $this->RegisterVariableBoolean('Power', '⚡ Power', '', 1);
        $this->EnableAction('Power');

        $this->RegisterVariableFloat('Volume', '🔊 Lautstärke', '', 2);
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', '🔇 Mute', '', 3);
        $this->EnableAction('Mute');

        $this->RegisterVariableInteger('Source', '🎵 Quelle', 'LYNG.Source', 4);
        $this->EnableAction('Source');

        $this->RegisterVariableInteger('AudioMode', '🎛️ Audio Mode', 'LYNG.AudioMode', 5);
        $this->EnableAction('AudioMode');

        $this->RegisterVariableInteger('Voicing', '🗣️ Voicing', 'LYNG.Voicing', 6);
        $this->EnableAction('Voicing');

        $this->RegisterVariableString('AudioTypeIn', '📥 Audio Type In', '', 7);
        $this->RegisterVariableString('AudioTypeOut', '📤 Audio Type Out', '', 8);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Power'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Power'
        ]);

        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Mute'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON'         => 'Speaker'
        ]);


        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Volume'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => 'Intensity',
            'SUFFIX'       => ' dB',
            'MIN'          => -99.9,
            'MAX'          => 24.0,
            'STEP'         => 0.5
        ]);

        if (!IPS_VariableProfileExists('LYNG.Source')) {
            IPS_CreateVariableProfile('LYNG.Source', 1);
            IPS_SetVariableProfileIcon('LYNG.Source', 'TV');
        }
        if (!IPS_VariableProfileExists('LYNG.AudioMode')) {
            IPS_CreateVariableProfile('LYNG.AudioMode', 1);
            IPS_SetVariableProfileIcon('LYNG.AudioMode', 'Sound');
        }
        if (!IPS_VariableProfileExists('LYNG.Voicing')) {
            IPS_CreateVariableProfile('LYNG.Voicing', 1);
            IPS_SetVariableProfileIcon('LYNG.Voicing', 'Speaker');
        }

        if ($this->HasActiveParent()) {
            $this->UpdateData();
        }

        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentId > 0) {
            $this->RegisterMessage($parentId, 10505 /* IM_CHANGESTATUS */);
        }

        // Regelmäßiges Polling (alle 30 Sekunden) als Fallback
        $this->RegisterTimer('UpdatePolling', 30000, 'LYNG_UpdateData($_IPS[\'TARGET\']);');
    }

    protected function Log(string $text): void
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'LyngdorfMP60: ' . $text);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === 10505) { // IM_CHANGESTATUS
            if ($Data[0] === 102) { // IS_ACTIVE
                $this->SendDebug('System', 'Socket reconnected, forcing UpdateData', 0);
                $this->Log('Verbindung zum Gerät (wieder-)hergestellt. Lade Status...');
                $this->UpdateData();
            }
        }
    }

    public function RequestAction(string $Ident, $Value): void
    {
        switch ($Ident) {
            case 'Power':
                if ($Value) {
                    $this->Log('Schalte Gerät EIN (POWERONMAIN)');
                    $this->SendCommand('!POWERONMAIN');
                } else {
                    $this->Log('Schalte Gerät AUS (POWEROFFMAIN)');
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
            $payload = is_string($data->Buffer) ? hex2bin($data->Buffer) : '';
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
            if ($this->GetValue('Power') !== $power) {
                $this->Log('Status geändert: Power = ' . ($power ? 'ON' : 'OFF'));
            }
            $this->SetValue('Power', $power);
            $this->UpdateVisibility($power);
        } 
        elseif ($command === 'POWERONMAIN' || $command === 'PON' || $command === 'POWERON') {
            if (!$this->GetValue('Power')) {
                $this->Log('Status geändert: Power = ON');
            }
            $this->SetValue('Power', true);
            $this->UpdateVisibility(true);
        }
        elseif ($command === 'POWEROFFMAIN' || $command === 'POFF' || $command === 'POWEROFF') {
            if ($this->GetValue('Power')) {
                $this->Log('Status geändert: Power = OFF');
            }
            $this->SetValue('Power', false);
            $this->UpdateVisibility(false);
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
            IPS_SetVariableProfileAssociation('LYNG.Source', $index, $name, '', -1);
            $this->SetValue('Source', $index);
        }
        elseif (preg_match('/^SRC\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('Source', intval($matches[1]));
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            IPS_SetVariableProfileAssociation('LYNG.AudioMode', $index, $name, '', -1);
            $this->SetValue('AudioMode', $index);
        }
        elseif (preg_match('/^AUDMODE\((\d+)\)$/', $command, $matches)) {
            $this->SetValue('AudioMode', intval($matches[1]));
        }
        elseif (preg_match('/^RPVOI\((\d+)\)"(.*)"$/', $command, $matches)) {
            $index = intval($matches[1]);
            $name = $matches[2];
            IPS_SetVariableProfileAssociation('LYNG.Voicing', $index, $name, '', -1);
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
            'Buffer' => bin2hex($command . "\r")
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


}
