<?php

declare(strict_types=1);

// Deutsche Pluralregeln (vereinfachte Drei-Wege-Regelung):
//   {1}    → endet auf 1 (nicht 11)        z. B. 1, 21, 31 …
//   [2,4]  → endet auf 2-4 (nicht 12-14)   z. B. 2, 3, 4, 22 …
//   [5,*]  → alles andere                   z. B. 5-20, 11-14, 0 …

return [
    // Anwesenheit
    'required'          => 'Das Feld :attribute ist erforderlich.',
    'present'           => 'Das Feld :attribute muss vorhanden sein.',
    'filled'            => 'Das Feld :attribute darf nicht leer sein.',
    'prohibited'        => 'Das Feld :attribute ist nicht erlaubt.',
    'prohibited_if'     => 'Das Feld :attribute ist nicht erlaubt, wenn :other gleich :when ist.',
    'prohibited_unless' => 'Das Feld :attribute ist nicht erlaubt, wenn :other nicht gleich :when ist.',
    'required_if'       => 'Das Feld :attribute ist erforderlich, wenn :other gleich :when ist.',
    'required_unless'   => 'Das Feld :attribute ist erforderlich, wenn :other nicht gleich :when ist.',
    'required_with'     => 'Das Feld :attribute ist erforderlich, wenn :values vorhanden ist.',
    'required_without'  => 'Das Feld :attribute ist erforderlich, wenn :values nicht vorhanden ist.',
    // Typ
    'string'            => 'Das Feld :attribute muss eine Zeichenkette sein.',
    'integer'           => 'Das Feld :attribute muss eine ganze Zahl sein.',
    'int'               => 'Das Feld :attribute muss eine ganze Zahl sein.',
    'float'             => 'Das Feld :attribute muss eine Zahl sein.',
    'numeric'           => 'Das Feld :attribute muss eine Zahl sein.',
    'boolean'           => 'Das Feld :attribute muss true oder false sein.',
    'bool'              => 'Das Feld :attribute muss true oder false sein.',
    'array'             => 'Das Feld :attribute muss ein Array sein.',
    // Format
    'email'             => 'Das Feld :attribute muss eine gültige E-Mail-Adresse enthalten.',
    'url'               => 'Das Feld :attribute muss eine gültige URL sein.',
    'alpha'             => 'Das Feld :attribute darf nur Buchstaben enthalten.',
    'alpha_num'         => 'Das Feld :attribute darf nur Buchstaben und Ziffern enthalten.',
    'digits'            => 'Das Feld :attribute darf nur Ziffern enthalten.',
    'digits_between'    => 'Das Feld :attribute muss zwischen :min und :max Ziffern enthalten.',
    'date'              => 'Das Feld :attribute muss ein gültiges Datum sein.',
    'date_format'       => 'Das Feld :attribute muss dem Format :format entsprechen.',
    'ip'                => 'Das Feld :attribute muss eine gültige IP-Adresse sein.',
    'ipv4'              => 'Das Feld :attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6'              => 'Das Feld :attribute muss eine gültige IPv6-Adresse sein.',
    'json'              => 'Das Feld :attribute muss ein gültiger JSON-String sein.',
    'uuid'              => 'Das Feld :attribute muss eine gültige UUID sein.',
    'mac_address'       => 'Das Feld :attribute muss eine gültige MAC-Adresse sein.',
    'regex'             => 'Das Feld :attribute hat ein ungültiges Format.',
    'not_regex'         => 'Das Feld :attribute hat ein ungültiges Format.',
    'lowercase'         => 'Das Feld :attribute muss in Kleinbuchstaben sein.',
    'uppercase'         => 'Das Feld :attribute muss in Großbuchstaben sein.',
    // Wert
    'min'               => 'Das Feld :attribute muss mindestens :min sein.',
    'max'               => 'Das Feld :attribute darf höchstens :max sein.',
    'min_length'        => '{1} Das Feld :attribute muss mindestens :min Zeichen enthalten.|[2,4] Das Feld :attribute muss mindestens :min Zeichen enthalten.|[5,*] Das Feld :attribute muss mindestens :min Zeichen enthalten.',
    'max_length'        => '{1} Das Feld :attribute darf höchstens :max Zeichen enthalten.|[2,4] Das Feld :attribute darf höchstens :max Zeichen enthalten.|[5,*] Das Feld :attribute darf höchstens :max Zeichen enthalten.',
    'size'              => 'Das Feld :attribute muss gleich :size sein.',
    'between'           => 'Das Feld :attribute muss zwischen :min und :max liegen.',
    'multiple_of'       => 'Das Feld :attribute muss ein Vielfaches von :value sein.',
    'in'                => 'Das Feld :attribute muss eines der folgenden sein: :values.',
    'not_in'            => 'Das Feld :attribute darf keines der folgenden sein: :values.',
    'accepted'          => 'Das Feld :attribute muss akzeptiert werden.',
    'declined'          => 'Das Feld :attribute muss abgelehnt werden.',
    'confirmed'         => 'Die Bestätigung des Feldes :attribute stimmt nicht überein.',
    'same'              => 'Das Feld :attribute muss mit dem Feld :other übereinstimmen.',
    'different'         => 'Das Feld :attribute muss sich vom Feld :other unterscheiden.',
    'starts_with'       => 'Das Feld :attribute muss mit :value beginnen.',
    'ends_with'         => 'Das Feld :attribute muss auf :value enden.',
    // Array
    'list'              => 'Das Feld :attribute muss eine Liste sein.',
    'distinct'          => 'Das Feld :attribute enthält doppelte Werte.',
    'min_items'         => '{1} Das Feld :attribute muss mindestens :min Element enthalten.|[2,4] Das Feld :attribute muss mindestens :min Elemente enthalten.|[5,*] Das Feld :attribute muss mindestens :min Elemente enthalten.',
    'max_items'         => '{1} Das Feld :attribute darf höchstens :max Element enthalten.|[2,4] Das Feld :attribute darf höchstens :max Elemente enthalten.|[5,*] Das Feld :attribute darf höchstens :max Elemente enthalten.',
];