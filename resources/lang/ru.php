<?php

declare(strict_types=1);

// Russian plural rules (simplified three-way split):
//   {1}    → ends in 1 (not 11)        e.g. 1, 21, 31 …
//   [2,4]  → ends in 2-4 (not 12-14)   e.g. 2, 3, 4, 22 …
//   [5,*]  → everything else            e.g. 5-20, 11-14, 0 …

return [
    // Presence
    'required'          => 'Поле :attribute обязательно для заполнения.',
    'present'           => 'Поле :attribute должно присутствовать.',
    'filled'            => 'Поле :attribute не должно быть пустым.',
    'prohibited'        => 'Поле :attribute запрещено.',
    'prohibited_if'     => 'Поле :attribute запрещено, когда :other равно :when.',
    'prohibited_unless' => 'Поле :attribute запрещено, если :other не равно :when.',
    'required_if'       => 'Поле :attribute обязательно, когда :other равно :when.',
    'required_unless'   => 'Поле :attribute обязательно, если :other не равно :when.',
    'required_with'     => 'Поле :attribute обязательно, когда присутствует :values.',
    'required_without'  => 'Поле :attribute обязательно, когда отсутствует :values.',
    // Type
    'string'            => 'Поле :attribute должно быть строкой.',
    'integer'           => 'Поле :attribute должно быть целым числом.',
    'int'               => 'Поле :attribute должно быть целым числом.',
    'float'             => 'Поле :attribute должно быть числом.',
    'numeric'           => 'Поле :attribute должно быть числом.',
    'boolean'           => 'Поле :attribute должно быть true или false.',
    'bool'              => 'Поле :attribute должно быть true или false.',
    'array'             => 'Поле :attribute должно быть массивом.',
    // Format
    'email'             => 'Поле :attribute должно содержать корректный email-адрес.',
    'url'               => 'Поле :attribute должно быть корректным URL.',
    'alpha'             => 'Поле :attribute может содержать только буквы.',
    'alpha_num'         => 'Поле :attribute может содержать только буквы и цифры.',
    'digits'            => 'Поле :attribute должно содержать только цифры.',
    'digits_between'    => 'Поле :attribute должно содержать от :min до :max цифр.',
    'date'              => 'Поле :attribute должно быть корректной датой.',
    'date_format'       => 'Поле :attribute должно соответствовать формату :format.',
    'ip'                => 'Поле :attribute должно быть корректным IP-адресом.',
    'ipv4'              => 'Поле :attribute должно быть корректным IPv4-адресом.',
    'ipv6'              => 'Поле :attribute должно быть корректным IPv6-адресом.',
    'json'              => 'Поле :attribute должно быть корректным JSON.',
    'uuid'              => 'Поле :attribute должно быть корректным UUID.',
    'mac_address'       => 'Поле :attribute должно быть корректным MAC-адресом.',
    'regex'             => 'Поле :attribute имеет неверный формат.',
    'not_regex'         => 'Поле :attribute имеет неверный формат.',
    'lowercase'         => 'Поле :attribute должно быть в нижнем регистре.',
    'uppercase'         => 'Поле :attribute должно быть в верхнем регистре.',
    // Value
    'min'               => 'Поле :attribute должно быть не менее :min.',
    'max'               => 'Поле :attribute не должно превышать :max.',
    'min_length'        => '{1} Поле :attribute должно содержать не менее :min символа.|[2,4] Поле :attribute должно содержать не менее :min символов.|[5,*] Поле :attribute должно содержать не менее :min символов.',
    'max_length'        => '{1} Поле :attribute не должно превышать :max символ.|[2,4] Поле :attribute не должно превышать :max символа.|[5,*] Поле :attribute не должно превышать :max символов.',
    'size'              => 'Поле :attribute должно быть равно :size.',
    'between'           => 'Поле :attribute должно быть между :min и :max.',
    'multiple_of'       => 'Поле :attribute должно быть кратно :value.',
    'in'                => 'Поле :attribute должно быть одним из: :values.',
    'not_in'            => 'Поле :attribute не должно быть одним из: :values.',
    'accepted'          => 'Поле :attribute должно быть принято.',
    'declined'          => 'Поле :attribute должно быть отклонено.',
    'confirmed'         => 'Подтверждение поля :attribute не совпадает.',
    'same'              => 'Поле :attribute должно совпадать с полем :other.',
    'different'         => 'Поле :attribute должно отличаться от поля :other.',
    'starts_with'       => 'Поле :attribute должно начинаться с :value.',
    'ends_with'         => 'Поле :attribute должно заканчиваться на :value.',
    // Array
    'list'              => 'Поле :attribute должно быть списком.',
    'distinct'          => 'Поле :attribute содержит дублирующиеся значения.',
    'min_items'         => '{1} Поле :attribute должно содержать не менее :min элемента.|[2,4] Поле :attribute должно содержать не менее :min элементов.|[5,*] Поле :attribute должно содержать не менее :min элементов.',
    'max_items'         => '{1} Поле :attribute не должно содержать более :max элемента.|[2,4] Поле :attribute не должно содержать более :max элементов.|[5,*] Поле :attribute не должно содержать более :max элементов.',
];
