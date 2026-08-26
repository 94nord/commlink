<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Danish translation of Laravel's default validator messages. Mirrors
    | lang/en/validation.php key-for-key so a rule added to a FormRequest
    | never silently falls back to English.
    |
    */

    'accepted' => ':attribute skal accepteres.',
    'accepted_if' => ':attribute skal accepteres, når :other er :value.',
    'active_url' => ':attribute skal være en gyldig URL.',
    'after' => ':attribute skal være en dato efter :date.',
    'after_or_equal' => ':attribute skal være en dato på eller efter :date.',
    'alpha' => ':attribute må kun indeholde bogstaver.',
    'alpha_dash' => ':attribute må kun indeholde bogstaver, tal, bindestreger og understreger.',
    'alpha_num' => ':attribute må kun indeholde bogstaver og tal.',
    'any_of' => ':attribute er ugyldig.',
    'array' => ':attribute skal være en liste.',
    'ascii' => ':attribute må kun indeholde alfanumeriske tegn og symboler.',
    'before' => ':attribute skal være en dato før :date.',
    'before_or_equal' => ':attribute skal være en dato på eller før :date.',
    'between' => [
        'array' => ':attribute skal have mellem :min og :max elementer.',
        'file' => ':attribute skal være mellem :min og :max kilobyte.',
        'numeric' => ':attribute skal være mellem :min og :max.',
        'string' => ':attribute skal være mellem :min og :max tegn.',
    ],
    'boolean' => ':attribute skal være sand eller falsk.',
    'can' => ':attribute indeholder en værdi, der ikke er tilladt.',
    'confirmed' => 'Bekræftelsen af :attribute stemmer ikke overens.',
    'contains' => ':attribute mangler en påkrævet værdi.',
    'current_password' => 'Adgangskoden er forkert.',
    'date' => ':attribute skal være en gyldig dato.',
    'date_equals' => ':attribute skal være en dato lig med :date.',
    'date_format' => ':attribute skal matche formatet :format.',
    'decimal' => ':attribute skal have :decimal decimaler.',
    'declined' => ':attribute skal afvises.',
    'declined_if' => ':attribute skal afvises, når :other er :value.',
    'different' => ':attribute og :other skal være forskellige.',
    'digits' => ':attribute skal være :digits cifre.',
    'digits_between' => ':attribute skal være mellem :min og :max cifre.',
    'dimensions' => ':attribute har ugyldige billeddimensioner.',
    'distinct' => ':attribute har en værdi, der er angivet mere end én gang.',
    'doesnt_contain' => ':attribute må ikke indeholde nogen af følgende: :values.',
    'doesnt_end_with' => ':attribute må ikke slutte med en af følgende: :values.',
    'doesnt_start_with' => ':attribute må ikke starte med en af følgende: :values.',
    'email' => ':attribute skal være en gyldig e-mailadresse.',
    'encoding' => ':attribute skal være kodet i :encoding.',
    'ends_with' => ':attribute skal slutte med en af følgende: :values.',
    'enum' => 'Den valgte :attribute er ugyldig.',
    'exists' => 'Den valgte :attribute er ugyldig.',
    'extensions' => ':attribute skal have en af følgende filtyper: :values.',
    'file' => ':attribute skal være en fil.',
    'filled' => ':attribute skal have en værdi.',
    'gt' => [
        'array' => ':attribute skal have mere end :value elementer.',
        'file' => ':attribute skal være større end :value kilobyte.',
        'numeric' => ':attribute skal være større end :value.',
        'string' => ':attribute skal være længere end :value tegn.',
    ],
    'gte' => [
        'array' => ':attribute skal have :value elementer eller flere.',
        'file' => ':attribute skal være større end eller lig med :value kilobyte.',
        'numeric' => ':attribute skal være større end eller lig med :value.',
        'string' => ':attribute skal være mindst :value tegn.',
    ],
    'hex_color' => ':attribute skal være en gyldig hexadecimal farve.',
    'image' => ':attribute skal være et billede.',
    'in' => 'Den valgte :attribute er ugyldig.',
    'in_array' => ':attribute skal findes i :other.',
    'in_array_keys' => ':attribute skal indeholde mindst én af følgende nøgler: :values.',
    'integer' => ':attribute skal være et heltal.',
    'ip' => ':attribute skal være en gyldig IP-adresse.',
    'ipv4' => ':attribute skal være en gyldig IPv4-adresse.',
    'ipv6' => ':attribute skal være en gyldig IPv6-adresse.',
    'json' => ':attribute skal være en gyldig JSON-streng.',
    'list' => ':attribute skal være en liste.',
    'lowercase' => ':attribute skal være med små bogstaver.',
    'lt' => [
        'array' => ':attribute skal have færre end :value elementer.',
        'file' => ':attribute skal være mindre end :value kilobyte.',
        'numeric' => ':attribute skal være mindre end :value.',
        'string' => ':attribute skal være kortere end :value tegn.',
    ],
    'lte' => [
        'array' => ':attribute må ikke have flere end :value elementer.',
        'file' => ':attribute skal være mindre end eller lig med :value kilobyte.',
        'numeric' => ':attribute skal være mindre end eller lig med :value.',
        'string' => ':attribute må højst være :value tegn.',
    ],
    'mac_address' => ':attribute skal være en gyldig MAC-adresse.',
    'max' => [
        'array' => ':attribute må ikke have flere end :max elementer.',
        'file' => ':attribute må ikke være større end :max kilobyte.',
        'numeric' => ':attribute må ikke være større end :max.',
        'string' => ':attribute må højst være :max tegn.',
    ],
    'max_digits' => ':attribute må ikke have flere end :max cifre.',
    'mimes' => ':attribute skal være en fil af typen: :values.',
    'mimetypes' => ':attribute skal være en fil af typen: :values.',
    'min' => [
        'array' => ':attribute skal have mindst :min elementer.',
        'file' => ':attribute skal være mindst :min kilobyte.',
        'numeric' => ':attribute skal være mindst :min.',
        'string' => ':attribute skal være mindst :min tegn.',
    ],
    'min_digits' => ':attribute skal have mindst :min cifre.',
    'missing' => ':attribute skal mangle.',
    'missing_if' => ':attribute skal mangle, når :other er :value.',
    'missing_unless' => ':attribute skal mangle, medmindre :other er :value.',
    'missing_with' => ':attribute skal mangle, når :values er til stede.',
    'missing_with_all' => ':attribute skal mangle, når :values alle er til stede.',
    'multiple_of' => ':attribute skal være et multiplum af :value.',
    'not_in' => 'Den valgte :attribute er ugyldig.',
    'not_regex' => ':attribute har et ugyldigt format.',
    'numeric' => ':attribute skal være et tal.',
    'password' => [
        'letters' => ':attribute skal indeholde mindst ét bogstav.',
        'mixed' => ':attribute skal indeholde mindst ét stort og ét lille bogstav.',
        'numbers' => ':attribute skal indeholde mindst ét tal.',
        'symbols' => ':attribute skal indeholde mindst ét symbol.',
        'uncompromised' => 'Den angivne :attribute er fundet i et datalæk. Vælg venligst en anden :attribute.',
    ],
    'present' => ':attribute skal være til stede.',
    'present_if' => ':attribute skal være til stede, når :other er :value.',
    'present_unless' => ':attribute skal være til stede, medmindre :other er :value.',
    'present_with' => ':attribute skal være til stede, når :values er til stede.',
    'present_with_all' => ':attribute skal være til stede, når :values alle er til stede.',
    'prohibited' => ':attribute er ikke tilladt.',
    'prohibited_if' => ':attribute er ikke tilladt, når :other er :value.',
    'prohibited_if_accepted' => ':attribute er ikke tilladt, når :other er accepteret.',
    'prohibited_if_declined' => ':attribute er ikke tilladt, når :other er afvist.',
    'prohibited_unless' => ':attribute er ikke tilladt, medmindre :other er i :values.',
    'prohibits' => ':attribute forhindrer :other i at være til stede.',
    'regex' => ':attribute har et ugyldigt format.',
    'required' => ':attribute skal udfyldes.',
    'required_array_keys' => ':attribute skal indeholde poster for: :values.',
    'required_if' => ':attribute skal udfyldes, når :other er :value.',
    'required_if_accepted' => ':attribute skal udfyldes, når :other er accepteret.',
    'required_if_declined' => ':attribute skal udfyldes, når :other er afvist.',
    'required_unless' => ':attribute skal udfyldes, medmindre :other er i :values.',
    'required_with' => ':attribute skal udfyldes, når :values er til stede.',
    'required_with_all' => ':attribute skal udfyldes, når :values alle er til stede.',
    'required_without' => ':attribute skal udfyldes, når :values ikke er angivet.',
    'required_without_all' => ':attribute skal udfyldes, når ingen af :values er angivet.',
    'same' => ':attribute og :other skal være ens.',
    'size' => [
        'array' => ':attribute skal indeholde :size elementer.',
        'file' => ':attribute skal være :size kilobyte.',
        'numeric' => ':attribute skal være :size.',
        'string' => ':attribute skal være :size tegn.',
    ],
    'starts_with' => ':attribute skal starte med en af følgende: :values.',
    'string' => ':attribute skal være en tekststreng.',
    'timezone' => ':attribute skal være en gyldig tidszone.',
    'unique' => ':attribute er allerede i brug.',
    'uploaded' => 'Upload af :attribute mislykkedes.',
    'uppercase' => ':attribute skal være med store bogstaver.',
    'url' => ':attribute skal være en gyldig URL.',
    'ulid' => ':attribute skal være en gyldig ULID.',
    'uuid' => ':attribute skal være en gyldig UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Every field name this app actually validates (server/app/Http/Requests),
    | so a Danish sentence never has a raw English/camelCase field name
    | stitched into the middle of it.
    |
    */

    'attributes' => [
        'name' => 'navn',
        'email' => 'e-mail',
        'phone' => 'telefonnummer',
        'address' => 'adresse',
        'body' => 'besked',
        'customerId' => 'kunde',
        'is_admin' => 'administrator',
        'password' => 'adgangskode',
        'device_name' => 'enhedsnavn',
    ],

];
