<?php

/**
 * Planes de estudio del catálogo demo, por clave TecNM, con su retícula: datos públicos de las retículas del
 * TecNM, aproximados (las claves, los semestres y la seriación de cada materia pueden no coincidir con la
 * retícula oficial). Los 6 planes vigentes de Campus II siguen las retículas públicas lo más cerca posible;
 * los de Campus I y los planes 2004 son verosímiles. Las materias se definen en subjects.php.
 *
 * - `grid`: semestre => claves de las materias sin especialidad.
 * - `specialties`: clave => nombre y su `grid`; `active` y `deleted` opcionales.
 * - `seriation`: cadenas de seriación; cada materia pide la anterior de su cadena.
 * - `corequisites`: pares [materia, correquisito].
 * - `optional`: materias que no son obligatorias.
 * - `removed`: materias quitadas de la retícula (borrado lógico), clave => semestre.
 * - `removed_seriation`: seriaciones quitadas (borrado lógico), pares [materia, requisito].
 * - `active` (true por omisión), `valid_from`, `total_credits` y `semesters` (por omisión, el último semestre
 *   de la retícula) son los del plan; el nombre es el de la carrera y la versión.
 *
 * Los créditos cuadran: las obligatorias sin especialidad más cualquiera de las especialidades dan
 * `total_credits` (260 en los planes vigentes: 210 de materias, servicio social, residencia, actividades
 * complementarias y 25 de especialidad). Los planes 2004 usan los créditos de entonces (2 por hora de teoría y
 * 1 por hora de práctica).
 *
 * @return array<string, array<string, mixed>>
 */
return [

    /* ----------------------------------------------------------------------------------------------------
     | Campus I y Campus II
     * ---------------------------------------------------------------------------------------------------- */

    'ISIC-2010-224' => [
        'career' => 'ISIC',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACF-0901', 'AED-1285', 'ACA-0907', 'AEF-1041', 'SCH-1024', 'ACC-0906'],
            2 => ['ACF-0902', 'AED-1286', 'AEC-1008', 'AEC-1058', 'ACF-0903', 'AEF-1052'],
            3 => ['ACF-0904', 'AED-1026', 'SCC-1005', 'SCC-1013', 'ACD-0908', 'SCF-1006'],
            4 => ['ACF-0905', 'SCC-1017', 'SCD-1027', 'AEF-1031', 'SCD-1022', 'SCD-1018'],
            5 => ['SCC-1010', 'AEC-1034', 'AEC-1061', 'SCA-1026', 'SCC-1007', 'SCD-1003'],
            6 => ['SCD-1015', 'SCD-1021', 'SCA-1025', 'SCB-1001', 'SCD-1011', 'SCC-1014', 'AC-ISIC'],
            7 => ['SCD-1016', 'SCD-1004', 'ACA-0909', 'SCG-1009', 'SCC-1023'],
            8 => ['SCC-1019', 'SCA-1002', 'ACA-0910', 'AEB-1055', 'SS-ISIC'],
            9 => ['SCC-1012', 'RP-ISIC'],
        ],
        'specialties' => [
            'ISIC-ISW-2020' => ['name' => 'Ingeniería de Software', 'grid' => [
                7 => ['ISD-2001'],
                8 => ['ISD-2002', 'ISD-2003'],
                9 => ['ISD-2004', 'ISD-2005'],
            ]],
            'ISIC-RCS-2020' => ['name' => 'Redes y Ciberseguridad', 'grid' => [
                7 => ['RSD-2001'],
                8 => ['RSD-2002', 'RSD-2003'],
                9 => ['RSD-2004', 'RSD-2005'],
            ]],
            'ISIC-CDA-2022' => ['name' => 'Ciencia de Datos e Inteligencia Artificial Aplicada', 'grid' => [
                7 => ['CDD-2201'],
                8 => ['CDD-2202', 'CDD-2203'],
                9 => ['CDD-2204', 'CDD-2205'],
            ]],
            // Caso extremo: especialidad capturada por error y quitada; no tiene materias.
            'ISIC-RYT-2014' => ['name' => 'Redes y Telecomunicaciones', 'deleted' => true, 'grid' => []],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902', 'ACF-0904', 'ACF-0905'],
            ['AED-1285', 'AED-1286', 'AED-1026', 'SCD-1027', 'AEB-1055'],
            ['AEF-1031', 'SCA-1026', 'SCB-1001'],
            ['SCC-1007', 'SCD-1011', 'SCG-1009'],
            ['AEF-1041', 'SCD-1015', 'SCD-1016'],
            ['AEC-1034', 'SCD-1021', 'SCD-1004', 'SCA-1002'],
            ['AEC-1061', 'SCA-1025'],
            ['SCF-1006', 'SCD-1018', 'SCD-1003', 'SCC-1014', 'SCC-1023'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['AEF-1052', 'SCD-1022'],
            ['ACF-0903', 'SCC-1017'],
            ['AED-1026', 'SCC-1019', 'SCC-1012'],
            ['SCD-1011', 'ISD-2001', 'ISD-2002', 'ISD-2004'],
            ['ISD-2003', 'ISD-2005'],
            ['SCD-1021', 'RSD-2001', 'RSD-2002', 'RSD-2004'],
            ['RSD-2001', 'RSD-2003'],
            ['RSD-2002', 'RSD-2005'],
            ['AEF-1052', 'CDD-2201', 'CDD-2202', 'CDD-2204'],
            ['CDD-2201', 'CDD-2203', 'CDD-2205'],
        ],
        'corequisites' => [
            ['SCD-1022', 'SCC-1017'],
            ['SCC-1014', 'SCD-1015'],
        ],
        'removed_seriation' => [
            ['SCC-1013', 'AEF-1052'],
        ],
    ],

    'IIND-2010-227' => [
        'career' => 'IIND',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACC-0906', 'ACA-0907', 'ACF-0901', 'INH-1032', 'AEC-1058', 'INN-1007'],
            2 => ['INC-1009', 'INC-1027', 'ACF-0902', 'AEC-1053', 'INC-1023', 'ACF-0903'],
            3 => ['ACF-0904', 'INC-1008', 'INF-1011', 'INJ-1013', 'INF-1026', 'INF-1015'],
            4 => ['INC-1020', 'ACD-0908', 'INF-1012', 'INJ-1014', 'INF-1018', 'INH-1005'],
            5 => ['INC-1021', 'INF-1006', 'INF-1010', 'INC-1001', 'INC-1017', 'AEC-1037'],
            6 => ['INF-1030', 'INC-1002', 'INF-1024', 'INF-1031', 'INC-1019', 'INF-1003', 'AC-IIND'],
            7 => ['ACA-0909', 'AED-1035', 'INF-1025', 'INF-1022', 'AEC-1047'],
            8 => ['ACA-0910', 'INF-1016', 'INC-1029', 'INH-1033', 'INC-1004', 'SS-IIND'],
            9 => ['RP-IIND'],
        ],
        'specialties' => [
            'IIND-MEA-2020' => ['name' => 'Manufactura Esbelta y Automatización', 'grid' => [
                7 => ['MAD-2001'],
                8 => ['MAD-2002', 'MAD-2003'],
                9 => ['MAD-2004', 'MAD-2005'],
            ]],
            'IIND-CYP-2020' => ['name' => 'Calidad y Productividad', 'grid' => [
                7 => ['QPD-2001'],
                8 => ['QPD-2002', 'QPD-2003'],
                9 => ['QPD-2004', 'QPD-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902', 'ACF-0904'],
            ['AEC-1053', 'INF-1011', 'INF-1012', 'INF-1006', 'AED-1035'],
            ['INJ-1013', 'INJ-1014', 'INF-1010'],
            ['INJ-1014', 'INF-1025'],
            ['INC-1020', 'INC-1021', 'INF-1030'],
            ['INC-1001', 'INC-1002'],
            ['INF-1026', 'INF-1031'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['INC-1017', 'INF-1024', 'INF-1016'],
            ['INC-1008', 'AEC-1037'],
            ['INF-1031', 'MAD-2001', 'MAD-2002', 'MAD-2004'],
            ['MAD-2001', 'MAD-2003'],
            ['INF-1006', 'QPD-2001', 'QPD-2002', 'QPD-2004'],
            ['QPD-2002', 'QPD-2005'],
        ],
        'corequisites' => [
            ['INJ-1013', 'INF-1011'],
        ],
        // Caso extremo: Contabilidad Financiera se capturó por error en la retícula y se quitó.
        'removed' => ['AEC-1008' => 3],
    ],

    'IGEM-2009-201' => [
        'career' => 'IGEM',
        'version' => '2009',
        'valid_from' => '2009-08-24',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACC-0906', 'ACF-0901', 'GED-0906', 'GEF-0916', 'GEC-0915', 'GEC-0917'],
            2 => ['GED-0934', 'GEF-0904', 'GED-0907', 'ACA-0907', 'ACF-0902', 'GED-0927'],
            3 => ['GED-0928', 'GEF-0932', 'GEF-0905', 'GED-0922', 'GEF-0909', 'ACF-0903'],
            4 => ['AEC-1037', 'GEF-0912', 'GEF-0925', 'GED-0923', 'GEF-0911', 'GEF-0926'],
            5 => ['GEF-0914', 'GEF-0913', 'GEF-0924', 'GED-0918', 'ACA-0909', 'AEC-1047'],
            6 => ['GEC-0901', 'GED-0910', 'GEF-0919', 'GED-0908', 'GED-0933', 'ACA-0910', 'AC-IGEM'],
            7 => ['GED-0903', 'GED-0931', 'GEF-0920', 'GED-0921', 'GEF-0930', 'ACD-0908'],
            8 => ['GEF-0902', 'GEC-0935', 'SS-IGEM'],
            9 => ['RP-IGEM'],
        ],
        'specialties' => [
            'IGEM-GFF-2020' => ['name' => 'Gestión Financiera y Fiscal', 'grid' => [
                7 => ['GFD-2001'],
                8 => ['GFD-2002', 'GFD-2003'],
                9 => ['GFD-2004', 'GFD-2005'],
            ]],
            'IGEM-CSC-2020' => ['name' => 'Cadena de Suministro y Comercio Exterior', 'grid' => [
                7 => ['GCD-2001'],
                8 => ['GCD-2002', 'GCD-2003'],
                9 => ['GCD-2004', 'GCD-2005'],
            ]],
            'IGEM-IEM-2022' => ['name' => 'Innovación y Emprendimiento', 'grid' => [
                7 => ['IED-2201'],
                8 => ['IED-2202', 'IED-2203'],
                9 => ['IED-2204', 'IED-2205'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902'],
            ['GEF-0932', 'GEF-0912', 'GEF-0913'],
            ['GEF-0904', 'GEF-0905', 'GEF-0925', 'GEF-0914'],
            ['GED-0922', 'GED-0923', 'GED-0918'],
            ['GEF-0924', 'GEF-0919', 'GEF-0920', 'GEF-0902'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['AEC-1047', 'GED-0933', 'GEF-0930'],
            ['GED-0910', 'GED-0931'],
            ['GEF-0909', 'GEF-0911'],
            ['GEF-0914', 'GFD-2001', 'GFD-2002', 'GFD-2004'],
            ['GFD-2001', 'GFD-2003'],
            ['GEF-0919', 'GCD-2001', 'GCD-2002', 'GCD-2004'],
            ['GCD-2001', 'GCD-2003', 'GCD-2005'],
            ['GED-0910', 'IED-2201', 'IED-2202', 'IED-2204'],
            ['IED-2201', 'IED-2203', 'IED-2205'],
        ],
        'corequisites' => [
            ['GEF-0926', 'GEF-0912'],
        ],
    ],

    'ILOG-2009-202' => [
        'career' => 'ILOG',
        'version' => '2009',
        'valid_from' => '2009-08-24',
        'total_credits' => 260,
        'grid' => [
            1 => ['LOH-0918', 'ACF-0901', 'ACC-0906', 'ACA-0907', 'AEC-1058', 'AEA-1011', 'LOC-0912'],
            2 => ['ACF-0902', 'AEC-1053', 'ACF-0903', 'LOF-0906', 'LOC-0909', 'AEF-1042'],
            3 => ['INF-1011', 'LOD-0919', 'INC-1020', 'LOC-0921', 'LOD-0907', 'LOF-0914'],
            4 => ['INF-1012', 'INC-1021', 'LOF-0904', 'LOD-0923', 'AEC-1037', 'LOC-0926'],
            5 => ['LOF-0917', 'LOD-0901', 'INF-1018', 'LOF-0902', 'AEC-1047', 'ACD-0908'],
            6 => ['LOF-0924', 'LOC-0916', 'LOC-0905', 'ACA-0909', 'LOD-0910', 'LOF-0920', 'AC-ILOG'],
            7 => ['LOF-0903', 'LOF-0915', 'ACA-0910', 'LOD-0922', 'AED-1035', 'LOC-0913'],
            8 => ['LOD-0927', 'LOF-0928', 'INH-1033', 'SS-ILOG'],
            9 => ['RP-ILOG'],
        ],
        'specialties' => [
            'ILOG-LIN-2020' => ['name' => 'Logística Internacional', 'grid' => [
                7 => ['LID-2001'],
                8 => ['LID-2002', 'LID-2003'],
                9 => ['LID-2004', 'LID-2005'],
            ]],
            'ILOG-ALA-2020' => ['name' => 'Analítica y Automatización Logística', 'grid' => [
                7 => ['ALD-2001'],
                8 => ['ALD-2002', 'ALD-2003'],
                9 => ['ALD-2004', 'ALD-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902'],
            ['AEC-1053', 'INF-1011', 'INF-1012', 'LOF-0928'],
            ['INC-1020', 'INC-1021', 'LOF-0920'],
            ['LOF-0904', 'LOF-0917'],
            ['LOD-0901', 'LOF-0924'],
            ['LOF-0906', 'LOF-0914', 'LOF-0915'],
            ['LOC-0905', 'LOC-0913'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['LOD-0919', 'LOD-0927'],
            ['LOF-0902', 'LOD-0922'],
            ['LOC-0905', 'LID-2001', 'LID-2002', 'LID-2004'],
            ['LID-2001', 'LID-2003', 'LID-2005'],
            ['LOF-0920', 'ALD-2001', 'ALD-2002', 'ALD-2004'],
            ['ALD-2001', 'ALD-2003'],
            ['ALD-2002', 'ALD-2005'],
        ],
        'corequisites' => [
            ['LOF-0917', 'LOD-0901'],
        ],
    ],

    'LADM-2010-234' => [
        'career' => 'LADM',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ADD-1038', 'ADD-1025', 'ACA-0907', 'ACC-0906', 'ADF-1028', 'ADF-1006'],
            2 => ['ADD-1020', 'ADF-1017', 'ADD-1011', 'ADD-1005', 'ADH-1037', 'ADD-1008'],
            3 => ['ADD-1021', 'ADF-1018', 'ADD-1009', 'ADD-1004', 'GED-0907', 'ADF-1007'],
            4 => ['ADD-1023', 'ADD-1034', 'ADF-1030', 'ADD-1022', 'GEF-0909', 'ADF-1029'],
            5 => ['ADD-1024', 'ADF-1010', 'ADD-1031', 'ADF-1027', 'ADF-1001', 'ACD-0908'],
            6 => ['ADD-1035', 'ACA-0909', 'GED-0933', 'ADD-1026', 'ADF-1002', 'ADF-1016', 'AC-LADM'],
            7 => ['ADD-1013', 'ACA-0910', 'ADD-1019', 'ADD-1033', 'ADD-1003', 'GED-0931'],
            8 => ['ADD-1012', 'SS-LADM'],
            9 => ['RP-LADM'],
        ],
        'specialties' => [
            'LADM-MDI-2020' => ['name' => 'Mercadotecnia Digital', 'grid' => [
                7 => ['MDD-2001'],
                8 => ['MDD-2002', 'MDD-2003'],
                9 => ['MDD-2004', 'MDD-2005'],
            ]],
            'LADM-GTO-2020' => ['name' => 'Gestión del Talento y Desarrollo Organizacional', 'grid' => [
                7 => ['TOD-2001'],
                8 => ['TOD-2002', 'TOD-2003'],
                9 => ['TOD-2004', 'TOD-2005'],
            ]],
        ],
        'seriation' => [
            ['ADD-1038', 'ADD-1020', 'ADD-1021', 'ADD-1034', 'ADD-1033'],
            ['ADF-1017', 'ADF-1018', 'ADF-1030'],
            ['ADF-1006', 'ADD-1008', 'ADF-1007', 'ADF-1001', 'ADF-1002'],
            ['ADF-1028', 'ADF-1029'],
            ['ADD-1023', 'ADD-1024'],
            ['ADD-1022', 'ADD-1031', 'GED-0933'],
            ['GEF-0909', 'ADF-1027', 'ADF-1016'],
            ['ADD-1011', 'ADD-1009', 'ADF-1010'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['ADD-1026', 'GED-0931'],
            ['GED-0933', 'MDD-2001', 'MDD-2002', 'MDD-2004'],
            ['MDD-2001', 'MDD-2003', 'MDD-2005'],
            ['ADD-1024', 'TOD-2001', 'TOD-2002', 'TOD-2004'],
            ['TOD-2001', 'TOD-2003'],
        ],
        'corequisites' => [
            ['ADD-1019', 'ADD-1013'],
        ],
    ],

    'COPU-2010-205' => [
        'career' => 'COPU',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['CPF-1026', 'CPD-1001', 'ACC-0906', 'ACA-0907', 'CPF-1028', 'GED-0906'],
            2 => ['CPF-1008', 'CPD-1022', 'CPF-1019', 'CPF-1036', 'CPC-1035', 'CPF-1030'],
            3 => ['CPF-1009', 'CPD-1014', 'CPF-1020', 'ADF-1029', 'ADF-1027', 'AEC-1047'],
            4 => ['CPF-1007', 'ADD-1011', 'CPD-1011', 'CPF-1021', 'ACD-0908', 'CPA-1033'],
            5 => ['CPF-1006', 'CPF-1016', 'CPD-1012', 'CPF-1004', 'CPA-1034', 'CPD-1023'],
            6 => ['CPF-1010', 'CPF-1025', 'CPD-1013', 'CPF-1005', 'ACA-0909', 'CPF-1018', 'AC-COPU'],
            7 => ['CPF-1024', 'CPF-1003', 'CPF-1032', 'ACA-0910', 'CPC-1031', 'CPD-1002'],
            8 => ['CPF-1037', 'CPC-1038', 'SS-COPU'],
            9 => ['RP-COPU'],
        ],
        'specialties' => [
            'COPU-FIS-2020' => ['name' => 'Fiscal', 'grid' => [
                7 => ['FID-2001'],
                8 => ['FID-2002', 'FID-2003'],
                9 => ['FID-2004', 'FID-2005'],
            ]],
            'COPU-AYC-2020' => ['name' => 'Auditoría y Contraloría', 'grid' => [
                7 => ['AUD-2001'],
                8 => ['AUD-2002', 'AUD-2003'],
                9 => ['AUD-2004', 'AUD-2005'],
            ]],
        ],
        'seriation' => [
            ['CPF-1026', 'CPF-1008', 'CPF-1009', 'CPF-1007', 'CPF-1006', 'CPF-1010'],
            ['CPF-1019', 'CPF-1020'],
            ['CPD-1011', 'CPD-1012', 'CPD-1013'],
            ['CPF-1021', 'CPF-1004', 'CPF-1005'],
            ['CPD-1022', 'CPD-1014', 'ADD-1011'],
            ['CPF-1016', 'CPF-1025', 'CPF-1024'],
            ['CPF-1028', 'ADF-1029'],
            ['CPF-1030', 'ADF-1027'],
            ['CPA-1033', 'CPA-1034'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['CPF-1018', 'CPF-1003'],
            ['CPF-1025', 'FID-2001', 'FID-2002', 'FID-2004'],
            ['FID-2001', 'FID-2003', 'FID-2005'],
            ['CPF-1005', 'AUD-2001', 'AUD-2002', 'AUD-2004'],
            ['AUD-2001', 'AUD-2003'],
        ],
        'corequisites' => [
            ['CPF-1006', 'CPF-1016'],
            ['CPC-1031', 'CPF-1024'],
        ],
    ],

    /* ----------------------------------------------------------------------------------------------------
     | Solo Campus I
     * ---------------------------------------------------------------------------------------------------- */

    'ITIC-2010-225' => [
        'career' => 'ITIC',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACF-0901', 'AED-1285', 'ACC-0906', 'ACA-0907', 'AEF-1041', 'TIF-1001'],
            2 => ['ACF-0902', 'AED-1286', 'ACF-0903', 'AEF-1052', 'TIC-1002', 'SCD-1003'],
            3 => ['AED-1026', 'AEF-1031', 'TIC-1004', 'ACD-0908', 'TIF-1005', 'AEC-1008'],
            4 => ['AEC-1034', 'TID-1007', 'TID-1008', 'TIC-1009', 'TIF-1010', 'SCD-1011'],
            5 => ['AEC-1061', 'TID-1012', 'TIF-1013', 'AEB-1055', 'TIC-1014', 'SCA-1026'],
            6 => ['TID-1016', 'TIF-1017', 'TIC-1018', 'TID-1019', 'ACA-0909', 'TIC-1020', 'AC-ITIC'],
            7 => ['TIF-1021', 'TID-1022', 'ACA-0910', 'TIC-1023', 'TID-1024', 'TIF-1028'],
            8 => ['TID-1025', 'TIF-1026', 'TID-1027', 'SS-ITIC'],
            9 => ['RP-ITIC'],
        ],
        'specialties' => [
            'ITIC-CIB-2020' => ['name' => 'Ciberseguridad', 'grid' => [
                7 => ['CBD-2001'],
                8 => ['CBD-2002', 'CBD-2003'],
                9 => ['CBD-2004', 'CBD-2005'],
            ]],
            'ITIC-DSN-2020' => ['name' => 'Desarrollo de Soluciones en la Nube', 'grid' => [
                7 => ['NBD-2001'],
                8 => ['NBD-2002', 'NBD-2003'],
                9 => ['NBD-2004', 'NBD-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902'],
            ['AED-1285', 'AED-1286', 'AED-1026', 'TID-1008'],
            ['AEF-1031', 'TIF-1013'],
            ['AEF-1031', 'SCA-1026'],
            ['TID-1007', 'TID-1012', 'TIF-1017'],
            ['TIC-1004', 'TIF-1010'],
            ['TIC-1020', 'TIF-1021'],
            ['AEB-1055', 'TID-1016', 'TID-1024'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['TIF-1017', 'CBD-2001', 'CBD-2002', 'CBD-2004'],
            ['CBD-2001', 'CBD-2003', 'CBD-2005'],
            ['AEB-1055', 'NBD-2001', 'NBD-2002', 'NBD-2004'],
            ['NBD-2001', 'NBD-2003'],
            ['NBD-2002', 'NBD-2005'],
        ],
        'corequisites' => [
            ['TIF-1005', 'TIC-1004'],
        ],
    ],

    'IELE-2010-209' => [
        'career' => 'IELE',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACF-0901', 'AEC-1058', 'ACC-0906', 'ACA-0907', 'AEA-1011', 'ELH-1001'],
            2 => ['ACF-0902', 'ACF-0903', 'AEF-1042', 'AEA-1057', 'AEC-1053', 'ELC-1002'],
            3 => ['ACF-0904', 'AEF-1020', 'AEF-1009', 'ELD-1003', 'AEC-1054', 'ACD-0908'],
            4 => ['ACF-0905', 'AEF-1010', 'AEF-1022', 'AEF-1045', 'ELF-1004', 'AEF-1060'],
            5 => ['AEF-1048', 'AEF-1023', 'AED-1049', 'ELF-1005', 'ELD-1006', 'ELD-1007'],
            6 => ['AEF-1050', 'AED-1059', 'ELF-1008', 'ELD-1009', 'ELC-1010', 'ACA-0909', 'AC-IELE'],
            7 => ['ELF-1011', 'ELD-1012', 'ELD-1013', 'ACA-0910', 'ELC-1014', 'ELF-1018'],
            8 => ['ELD-1015', 'ELC-1016', 'ELC-1017', 'SS-IELE'],
            9 => ['RP-IELE'],
        ],
        'specialties' => [
            'IELE-SEP-2020' => ['name' => 'Sistemas Eléctricos de Potencia', 'grid' => [
                7 => ['SPD-2001'],
                8 => ['SPD-2002', 'SPD-2003'],
                9 => ['SPD-2004', 'SPD-2005'],
            ]],
            'IELE-ERE-2020' => ['name' => 'Energías Renovables', 'grid' => [
                7 => ['ERD-2001'],
                8 => ['ERD-2002', 'ERD-2003'],
                9 => ['ERD-2004', 'ERD-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902', 'ACF-0904', 'ACF-0905'],
            ['AEF-1009', 'AEF-1010', 'AEF-1048', 'ELF-1008', 'ELF-1011'],
            ['AEF-1010', 'ELF-1005'],
            ['AEF-1022', 'AEF-1023'],
            ['AEF-1022', 'AED-1059'],
            ['AEF-1020', 'ELF-1004'],
            ['ELD-1006', 'ELD-1013'],
            ['AEF-1050', 'ELD-1012'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['ELF-1008', 'SPD-2001', 'SPD-2002', 'SPD-2004'],
            ['SPD-2001', 'SPD-2003', 'SPD-2005'],
            ['AED-1059', 'ERD-2001', 'ERD-2002', 'ERD-2004'],
            ['ERD-2001', 'ERD-2003', 'ERD-2005'],
        ],
        'corequisites' => [
            ['ELD-1003', 'AEF-1009'],
        ],
    ],

    'IELC-2010-211' => [
        'career' => 'IELC',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACF-0901', 'AEC-1058', 'ACC-0906', 'ACA-0907', 'ETF-1001', 'AEA-1057'],
            2 => ['ACF-0902', 'ACF-0903', 'AEF-1042', 'AEC-1053', 'ETD-1002', 'ETC-1003'],
            3 => ['ACF-0904', 'AEF-1020', 'AEF-1009', 'AEC-1054', 'ACD-0908', 'ETD-1004'],
            4 => ['ACF-0905', 'AEF-1010', 'AEF-1022', 'AEF-1023', 'ETF-1005', 'ETD-1006'],
            5 => ['ETF-1007', 'ETD-1008', 'AED-1051', 'AED-1049', 'ETF-1009', 'ELF-1004'],
            6 => ['AEF-1050', 'AED-1059', 'ETD-1011', 'ETF-1012', 'ETD-1013', 'ACA-0909', 'AC-IELC'],
            7 => ['ETF-1014', 'ETD-1015', 'ACA-0910', 'ETC-1016', 'ETD-1017', 'ETF-1020'],
            8 => ['ETD-1018', 'ETD-1019', 'SS-IELC'],
            9 => ['RP-IELC'],
        ],
        'specialties' => [
            'IELC-TEL-2020' => ['name' => 'Telecomunicaciones', 'grid' => [
                7 => ['TLD-2001'],
                8 => ['TLD-2002', 'TLD-2003'],
                9 => ['TLD-2004', 'TLD-2005'],
            ]],
            'IELC-DSE-2020' => ['name' => 'Diseño de Sistemas Electrónicos', 'grid' => [
                7 => ['DED-2001'],
                8 => ['DED-2002', 'DED-2003'],
                9 => ['DED-2004', 'DED-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902', 'ACF-0904', 'ACF-0905'],
            ['AEF-1009', 'AEF-1022', 'ETD-1008', 'AED-1059'],
            ['AEF-1009', 'AEF-1010'],
            ['ETF-1005', 'ETF-1007'],
            ['AEF-1023', 'AED-1051'],
            ['ETF-1009', 'ETF-1012', 'ETD-1017', 'TLD-2002'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['ETF-1012', 'TLD-2001', 'TLD-2002', 'TLD-2004'],
            ['TLD-2001', 'TLD-2003', 'TLD-2005'],
            ['ETD-1013', 'DED-2001', 'DED-2002', 'DED-2004'],
            ['DED-2001', 'DED-2003', 'DED-2005'],
        ],
        'corequisites' => [
            ['ETD-1006', 'AEF-1022'],
        ],
    ],

    'IMEC-2010-228' => [
        'career' => 'IMEC',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACF-0901', 'AEC-1058', 'ACC-0906', 'ACA-0907', 'AEA-1011', 'MEF-1001'],
            2 => ['ACF-0902', 'ACF-0903', 'AEF-1042', 'AEA-1057', 'AEC-1053', 'AEC-1046'],
            3 => ['ACF-0904', 'AEC-1018', 'AEF-1045', 'INC-1023', 'AEC-1054', 'ACD-0908'],
            4 => ['ACF-0905', 'AEC-1019', 'AED-1043', 'AEF-1060', 'AEF-1020', 'MEF-1002'],
            5 => ['MEF-1003', 'MED-1004', 'AEC-1063', 'AEF-1009', 'MEF-1005', 'MED-1006'],
            6 => ['MEF-1007', 'MED-1008', 'MED-1009', 'AED-1049', 'AED-1065', 'ACA-0909', 'AC-IMEC'],
            7 => ['MEF-1010', 'MED-1011', 'AEF-1064', 'ACA-0910', 'MEC-1012', 'AEF-1050'],
            8 => ['MED-1013', 'INF-1018', 'MEF-1016', 'SS-IMEC'],
            9 => ['RP-IMEC'],
        ],
        'specialties' => [
            'IMEC-DMA-2020' => ['name' => 'Diseño Mecánico Avanzado', 'grid' => [
                7 => ['DMD-2001'],
                8 => ['DMD-2002', 'DMD-2003'],
                9 => ['DMD-2004', 'DMD-2005'],
            ]],
            'IMEC-TYE-2020' => ['name' => 'Térmica y Energía', 'grid' => [
                7 => ['TED-2001'],
                8 => ['TED-2002', 'TED-2003'],
                9 => ['TED-2004', 'TED-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902', 'ACF-0904', 'ACF-0905'],
            ['AEF-1042', 'AEC-1018', 'AEC-1019', 'MED-1009'],
            ['AEC-1018', 'AED-1043', 'MEF-1003', 'MEF-1007'],
            ['AEF-1045', 'MED-1004', 'MEF-1010'],
            ['AEF-1060', 'MEF-1005', 'MED-1008'],
            ['MED-1004', 'MED-1011', 'MED-1013'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['MEF-1007', 'DMD-2001', 'DMD-2002', 'DMD-2004'],
            ['DMD-2001', 'DMD-2003'],
            ['MED-1004', 'TED-2001', 'TED-2002', 'TED-2004'],
            ['TED-2001', 'TED-2003', 'TED-2005'],
        ],
        'corequisites' => [
            ['MED-1006', 'MEF-1005'],
        ],
    ],

    'IEME-2010-210' => [
        'career' => 'IEME',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACF-0901', 'AEC-1058', 'ACC-0906', 'ACA-0907', 'AEA-1011', 'EMF-1001'],
            2 => ['ACF-0902', 'ACF-0903', 'AEF-1042', 'AEA-1057', 'AEC-1053', 'AEC-1046'],
            3 => ['ACF-0904', 'AEC-1018', 'AEF-1020', 'INC-1023', 'AEC-1054', 'ACD-0908'],
            4 => ['ACF-0905', 'AEC-1019', 'AEF-1009', 'AED-1043', 'AEF-1045', 'EMD-1002'],
            5 => ['AEF-1010', 'AEF-1060', 'AEF-1022', 'EMD-1003', 'AEC-1063', 'AEF-1023'],
            6 => ['AEF-1048', 'AED-1065', 'AED-1049', 'EMD-1005', 'EMD-1006', 'ACA-0909', 'AC-IEME'],
            7 => ['AEF-1050', 'EMD-1007', 'ELD-1009', 'ACA-0910', 'AEF-1064', 'ELD-1013'],
            8 => ['MEF-1010', 'ELC-1014', 'EMD-1012', 'SS-IEME'],
            9 => ['RP-IEME'],
        ],
        'specialties' => [
            'IEME-AUT-2020' => ['name' => 'Automatización Industrial', 'grid' => [
                7 => ['AID-2001'],
                8 => ['AID-2002', 'AID-2003'],
                9 => ['AID-2004', 'AID-2005'],
            ]],
            'IEME-MIN-2020' => ['name' => 'Mantenimiento Industrial', 'grid' => [
                7 => ['MID-2001'],
                8 => ['MID-2002', 'MID-2003'],
                9 => ['MID-2004', 'MID-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902', 'ACF-0904', 'ACF-0905'],
            ['AEF-1009', 'AEF-1010', 'AEF-1048', 'EMD-1007'],
            ['AEF-1045', 'EMD-1003', 'EMD-1005'],
            ['AEF-1042', 'AEC-1018', 'AEC-1019', 'EMD-1006'],
            ['AEF-1022', 'AED-1049'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['AED-1049', 'AID-2001', 'AID-2002', 'AID-2004'],
            ['AID-2001', 'AID-2003', 'AID-2005'],
            ['AED-1065', 'MID-2001', 'MID-2002', 'MID-2004'],
            ['MID-2001', 'MID-2003'],
        ],
        'corequisites' => [
            ['AEF-1023', 'AEF-1022'],
        ],
    ],

    'IMCT-2010-229' => [
        'career' => 'IMCT',
        'version' => '2010',
        'valid_from' => '2010-08-23',
        'total_credits' => 260,
        'grid' => [
            1 => ['ACF-0901', 'AEC-1058', 'ACC-0906', 'ACA-0907', 'AEA-1011', 'MTF-1001'],
            2 => ['ACF-0902', 'ACF-0903', 'AEF-1042', 'AEA-1057', 'AEC-1053', 'AEC-1046'],
            3 => ['ACF-0904', 'AEC-1018', 'AEF-1020', 'INC-1023', 'AEC-1054', 'ACD-0908'],
            4 => ['ACF-0905', 'AEC-1019', 'AEF-1009', 'AED-1043', 'MEF-1002', 'AEF-1045'],
            5 => ['AEF-1022', 'AEF-1023', 'MTD-1003', 'AEC-1063', 'AED-1065', 'MTF-1004'],
            6 => ['AED-1051', 'AEF-1050', 'AED-1049', 'MTD-1005', 'AED-1059', 'ACA-0909', 'AC-IMCT'],
            7 => ['MTD-1006', 'MTF-1007', 'ACA-0910', 'MTD-1008', 'AEF-1064', 'MTC-1009'],
            8 => ['MTD-1010', 'MTF-1011', 'MTD-1012', 'SS-IMCT'],
            9 => ['RP-IMCT'],
        ],
        'specialties' => [
            'IMCT-AYR-2020' => ['name' => 'Automatización y Robótica', 'grid' => [
                7 => ['ARD-2001'],
                8 => ['ARD-2002', 'ARD-2003'],
                9 => ['ARD-2004', 'ARD-2005'],
            ]],
            'IMCT-SEI-2020' => ['name' => 'Sistemas Embebidos e Internet de las Cosas', 'grid' => [
                7 => ['SED-2001'],
                8 => ['SED-2002', 'SED-2003'],
                9 => ['SED-2004', 'SED-2005'],
            ]],
        ],
        'seriation' => [
            ['ACF-0901', 'ACF-0902', 'ACF-0904', 'ACF-0905'],
            ['AEF-1009', 'AEF-1022', 'AED-1059'],
            ['AEF-1023', 'AED-1051', 'MTF-1011'],
            ['AEC-1018', 'AEC-1019', 'MTF-1004', 'AEF-1050', 'MTD-1010'],
            ['AED-1049', 'MTD-1006'],
            ['ACC-0906', 'ACA-0909', 'ACA-0910'],
            ['AED-1051', 'ARD-2001', 'ARD-2002', 'ARD-2004'],
            ['ARD-2001', 'ARD-2003'],
            ['MTF-1007', 'ARD-2002'],
            ['AED-1051', 'SED-2001', 'SED-2002', 'SED-2004'],
            ['SED-2001', 'SED-2003', 'SED-2005'],
        ],
        'corequisites' => [
            ['MTD-1005', 'AED-1051'],
        ],
    ],

    /* ----------------------------------------------------------------------------------------------------
     | Planes anteriores, inactivos, que todavía cursan alumnos
     * ---------------------------------------------------------------------------------------------------- */

    'ISIC-2004-296' => [
        'career' => 'ISIC',
        'version' => '2004',
        'valid_from' => '2004-08-16',
        'active' => false,
        'total_credits' => 344,
        'grid' => [
            1 => ['ACM-0403', 'SCD-0401', 'SCM-0402', 'ACT-0401', 'SCC-0403', 'ACH-0402'],
            2 => ['ACM-0404', 'SCD-0404', 'ACM-0406', 'ACM-0402', 'SCC-0405', 'ACQ-0401'],
            3 => ['ACM-0407', 'SCD-0406', 'SCF-0407', 'SCC-0408', 'SCC-0409'],
            4 => ['ACM-0405', 'SCD-0410', 'SCD-0411', 'SCB-0412', 'SCC-0413'],
            5 => ['SCD-0414', 'SCB-0415', 'SCD-0416', 'SCC-0417', 'SCD-0418', 'SCC-0419'],
            6 => ['SCD-0420', 'SCD-0421', 'SCD-0422', 'SCB-0423', 'SCC-0424', 'AC-ISIC'],
            7 => ['SCD-0425', 'SCD-0426', 'ACT-0405', 'SCC-0427', 'SCD-0428'],
            8 => ['SCD-0429', 'ACT-0406', 'SCC-0430', 'SS-ISIC'],
            9 => ['RP-ISIC'],
        ],
        'specialties' => [
            'ISIC-SDI-2004' => ['name' => 'Sistemas Distribuidos', 'active' => false, 'grid' => [
                8 => ['SDD-0401'],
                9 => ['SDD-0402', 'SDD-0403'],
            ]],
        ],
        'seriation' => [
            ['ACM-0403', 'ACM-0404', 'ACM-0407', 'ACM-0405'],
            ['SCD-0401', 'SCD-0404', 'SCD-0406', 'SCD-0411'],
            ['SCB-0412', 'SCB-0415', 'SCB-0423'],
            ['SCD-0416', 'SCD-0422', 'SCC-0430'],
            ['SCD-0420', 'SCD-0426'],
            ['SCD-0421', 'SCD-0425'],
            ['ACT-0401', 'ACT-0405', 'ACT-0406'],
            ['SCD-0406', 'SDD-0401', 'SDD-0402'],
            ['SDD-0401', 'SDD-0403'],
        ],
    ],

    'IIND-2004-298' => [
        'career' => 'IIND',
        'version' => '2004',
        'valid_from' => '2004-08-16',
        'active' => false,
        'total_credits' => 291,
        'grid' => [
            1 => ['ACM-0403', 'ACT-0401', 'INB-0401', 'ACQ-0401', 'INC-0402'],
            2 => ['ACM-0404', 'ACM-0406', 'ACM-0402', 'INC-0403', 'INC-0404'],
            3 => ['ACM-0407', 'INC-0405', 'INJ-0406', 'INC-0407', 'INF-0408'],
            4 => ['INC-0409', 'INC-0410', 'INJ-0411', 'INC-0412', 'INC-0413'],
            5 => ['INC-0414', 'INC-0415', 'INC-0416', 'INC-0417', 'INC-0418'],
            6 => ['INC-0419', 'INC-0420', 'INC-0421', 'INC-0422', 'AC-IIND'],
            7 => ['INC-0423', 'INC-0424', 'ACT-0405', 'INC-0425', 'INC-0426'],
            8 => ['ACT-0406', 'INC-0427', 'INC-0428', 'SS-IIND'],
            9 => ['RP-IIND'],
        ],
        'seriation' => [
            ['ACM-0403', 'ACM-0404', 'ACM-0407'],
            ['ACM-0402', 'INC-0405', 'INC-0410', 'INC-0415'],
            ['INJ-0406', 'INJ-0411', 'INC-0416'],
            ['INC-0409', 'INC-0414', 'INC-0419'],
            ['INC-0417', 'INC-0420'],
            ['INC-0407', 'INC-0421'],
            ['ACT-0401', 'ACT-0405', 'ACT-0406'],
            ['INC-0413', 'INC-0422', 'INC-0427'],
        ],
    ],

    // Caso extremo: 10 semestres, para probar el ancho de la cuadrícula, y una optativa de lengua extranjera.
    'LADM-2004-300' => [
        'career' => 'LADM',
        'version' => '2004',
        'valid_from' => '2004-08-16',
        'active' => false,
        'total_credits' => 341,
        'grid' => [
            1 => ['ADM-0401', 'ADM-0402', 'ADM-0403', 'ACT-0401', 'ACH-0402'],
            2 => ['ADM-0404', 'ADM-0405', 'ADM-0406', 'ADM-0407', 'ADM-0408'],
            3 => ['ADM-0409', 'ADM-0410', 'ADM-0411', 'ADM-0412', 'ADM-0413'],
            4 => ['ADM-0414', 'ADM-0415', 'ADM-0416', 'ADM-0417', 'ADM-0418'],
            5 => ['ADM-0419', 'ADM-0420', 'ADM-0421', 'ADM-0422', 'ADM-0423'],
            6 => ['ADM-0424', 'ADM-0425', 'ADM-0426', 'ADM-0427', 'AC-LADM'],
            7 => ['ADM-0428', 'ADM-0429', 'ACT-0405', 'ADM-0430', 'LEX-0401'],
            8 => ['ADM-0432', 'ACT-0406', 'ADM-0433', 'ADM-0434'],
            9 => ['ADM-0435', 'ADM-0436', 'SS-LADM'],
            10 => ['RP-LADM'],
        ],
        'specialties' => [
            'LADM-MER-2004' => ['name' => 'Mercadotecnia', 'grid' => [
                8 => ['MKD-0401'],
                9 => ['MKD-0402'],
                10 => ['MKD-0403'],
            ]],
        ],
        'optional' => ['LEX-0401'],
        'seriation' => [
            ['ADM-0401', 'ADM-0404'],
            ['ADM-0402', 'ADM-0405', 'ADM-0423'],
            ['ADM-0403', 'ADM-0406', 'ADM-0411', 'ADM-0424', 'ADM-0428'],
            ['ADM-0409', 'ADM-0414', 'ADM-0419'],
            ['ADM-0407', 'ADM-0410'],
            ['ADM-0407', 'ADM-0415', 'ADM-0420'],
            ['ADM-0413', 'ADM-0416'],
            ['ADM-0417', 'ADM-0421'],
            ['ADM-0418', 'ADM-0422', 'ADM-0427'],
            ['ADM-0425', 'ADM-0429'],
            ['ACT-0401', 'ACT-0405', 'ACT-0406'],
            ['ADM-0427', 'MKD-0401', 'MKD-0402', 'MKD-0403'],
        ],
    ],

    // Caso extremo: plan de una carrera que ya no se ofrece, sin retícula, semestres ni créditos capturados
    // (la exportación del SII no los trae).
    'LINF-2010-220' => [
        'career' => 'LINF',
        'version' => '2010',
        'active' => false,
        'grid' => [],
    ],
];
