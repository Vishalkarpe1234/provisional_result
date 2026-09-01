<?php
declare(strict_types=1);

/**
 * Everything you are likely to change lives in this file.
 *
 * Note that maximum marks are NOT listed here - they are read from each
 * sheet's own sub-header, e.g. "CCE exam (25)". Change a total in Excel and
 * the marksheet follows automatically.
 */
return [

    /**
     * The semesters offered on the first page.
     *
     * Add one by adding an entry here - nothing else needs changing, because
     * subject names, credits and components are all read from that semester's
     * teaching scheme.
     */
    'semesters' => [
        '1' => [
            'label'       => '1st Semester',
            'course_name' => 'Master of Computer Application (Integrated)',
            'passing_year' => '2026',
        ],
        '2' => [
            'label'       => '2nd Semester',
            'course_name' => 'Master of Computer Application (Integrated)',
            'passing_year' => '2026',
        ],
        '3' => [
            'label'       => '3rd Semester',
            'course_name' => 'Master of Computer Application (Integrated)',
            'passing_year' => '2026',
        ],
        '4' => [
            'label'       => '4th Semester',
            'course_name' => 'Master of Computer Application (Integrated)',
            'passing_year' => '2026',
        ],
    ],

    /**
     * Subjects come from the uploaded teaching scheme: its Course Code and
     * Course Title columns give the code and name, its Credits column gives
     * the credits, and its Max Marks columns decide which components each
     * subject carries (a column above zero means that part applies).
     *
     * These lists are used only when no scheme has been uploaded for a
     * semester yet. Leave a semester out entirely to require its scheme.
     */
    'fallback_subjects' => [
        '4' => [
            ['code' => '150120402', 'credits' => 4, 'name' => 'Operating Systems',                                          'components' => ['E', 'CCE']],
            ['code' => '150120401', 'credits' => 4, 'name' => 'Programming in Python',                                      'components' => ['E', 'CCE']],
            ['code' => '150120408', 'credits' => 2, 'name' => 'Programming in Python -Lab',                                 'components' => ['V', 'CCE']],
            ['code' => '150120404', 'credits' => 2, 'name' => 'Basic Statistics using R -Lab',                              'components' => ['V', 'CCE']],
            ['code' => '150120409', 'credits' => 2, 'name' => 'Unified Modeling language - Lab',                            'components' => ['V', 'CCE']],
            ['code' => '150120412', 'credits' => 4, 'name' => 'Web Application Development using Java Framework (Spring)',  'components' => ['V', 'CCE']],
            ['code' => '150120413', 'credits' => 4, 'name' => 'Web Application Development using Laravel ( Laravel )',      'components' => ['V', 'CCE']],
            ['code' => '150120410', 'credits' => 2, 'name' => 'Social Media Marketing - SMM',                               'components' => ['E']],
            ['code' => '150120411', 'credits' => 2, 'name' => 'Yoga and Wellness',                                          'components' => ['E']],
        ],
    ],

    /** Marks per credit, used only when a subject's 'credits' is null. */
    'credit_unit' => 25,

    /**
     * Grade scale from the evaluation scheme. Highest band first; a subject's
     * percentage is matched against the first 'min' it reaches or exceeds.
     */
    'grade_scale' => [
        ['min' => 95, 'point' => 10.0, 'grade' => 'O+++'],
        ['min' => 90, 'point' => 9.5,  'grade' => 'O++'],
        ['min' => 85, 'point' => 9.0,  'grade' => 'O+'],
        ['min' => 80, 'point' => 8.5,  'grade' => 'O'],
        ['min' => 75, 'point' => 8.0,  'grade' => 'A++'],
        ['min' => 70, 'point' => 7.5,  'grade' => 'A+'],
        ['min' => 65, 'point' => 7.0,  'grade' => 'A'],
        ['min' => 60, 'point' => 6.5,  'grade' => 'B++'],
        ['min' => 55, 'point' => 6.0,  'grade' => 'B+'],
        ['min' => 50, 'point' => 5.5,  'grade' => 'B'],
        ['min' => 45, 'point' => 5.0,  'grade' => 'C'],
        ['min' => 40, 'point' => 4.5,  'grade' => 'D'],
        ['min' => 0,  'point' => 0.0,  'grade' => 'F'],
    ],

    /** Class awarded on SPI, highest band first. */
    'class_bands' => [
        ['min' => 7.5, 'label' => 'First class with distinction'],
        ['min' => 6.5, 'label' => 'First class'],
        ['min' => 5.5, 'label' => 'Higher Second Class'],
        ['min' => 5.0, 'label' => 'Second class'],
        ['min' => 4.0, 'label' => 'Pass class'],
        ['min' => 0,   'label' => 'Fail'],
    ],

    /** Grade F counts as a fail in that subject. */
    'fail_grade' => 'F',

    /** Show the SPI, equivalent percentage and class block. */
    'show_spi' => true,

    /** How each component is labelled on the printed marksheet. */
    'component_labels' => [
        'E'   => 'E',
        'V'   => 'V',
        'CCE' => 'CCE',
        'M'   => 'M',
    ],

    /**
     * Escape hatch for unusual column headings.
     *
     * E, V and CCE are detected automatically from wordings like "V (50)",
     * "CCE exam (25)", "Theory exam (70)" or "External (70)". If a sheet uses
     * something the app does not recognise, it says so on screen and names the
     * exact heading - map it here. Ignore the marks in brackets and match the
     * text only, e.g. a column headed "Ext (70)":
     *
     *     'Ext' => 'E',
     */
    'component_overrides' => [
        // 'Ext'             => 'E',
        // 'Exam'            => 'E',
        // 'University Exam' => 'E',
        // 'Pr'              => 'V',
    ],

    /**
     * Upload slots. 'kind' selects the reader:
     *   marks   - a student marks sheet (one row per student)
     *   credits - a teaching scheme (one row per subject)
     */
    'slots' => [
        'internal' => ['label' => 'Internal (CCE)',         'hint' => 'CCE marks for every subject', 'kind' => 'marks'],
        'theory'   => ['label' => 'External theory (E)',    'hint' => 'End-semester theory marks',   'kind' => 'marks'],
        'viva'     => ['label' => 'External practical (V)', 'hint' => 'Viva and practical marks',    'kind' => 'marks'],
        'scheme'   => ['label' => 'Teaching scheme',        'hint' => 'Subject codes and credits',   'kind' => 'credits'],
    ],

    /**
     * Force which column of the teaching scheme holds the credits, by heading
     * text. Leave null to detect it automatically - "Total Credits", "Credits"
     * and a bare "C" under a merged "Teaching Scheme" heading are all handled.
     */
    'credit_column' => null,

    /** Printed when a sheet does not supply its own. */
    'institute'   => 'L J Institute of Computer Applications',

    /* ------------------------------------------------------------------ *
     * Result document                                                     *
     * ------------------------------------------------------------------ */

    /**
     * The documents to generate. Each gets its own print button and prints on
     * its own sheet of paper.
     *
     *   type  - 'grades' (letters per component, with the SPI)
     *           or 'marks' (marks per component, with totals)
     *   style - 'official'   prints the college name and rule at the top
     *           'letterhead' leaves that space blank for pre-printed paper
     *   label - the wording on the print button
     *
     * Remove an entry to stop generating it; copy one to add a variant, e.g.
     * a letterhead marks sheet.
     */
    'documents' => [
        'grades' => [
            'type'  => 'grades',
            'style' => 'official',
            'title' => 'Provisional Result',
            'label' => 'Official grade sheet',
            'spi'   => true,
        ],
        'grades_letterhead' => [
            'type'  => 'grades',
            'style' => 'letterhead',
            'title' => 'Provisional Result',
            'label' => 'Grade sheet for letterhead',
            'spi'   => true,
        ],
        'marks' => [
            'type'  => 'marks',
            'style' => 'official',
            'title' => 'Statement of Marks',
            'label' => 'Marks sheet',
        ],
    ],

    /**
     * Height of the blank area reserved at the top of a 'letterhead'
     * document, for paper that is pre-printed with the college header.
     */
    'letterhead_space' => '38mm',

    /**
     * The printed heading used by documents with style 'official'.
     *
     * Save the college logo as assets/logo.png and it appears to the left of
     * the name; without that file the text simply centres.
     */
    'letterhead' => [
        'logo'        => 'assets/logo.png',
        'university'  => 'Lok Jagruti Kendra University',
        'tagline'     => 'University with a Difference',
        'established' => '(Lok Jagruti Kendra University Established by Gujarat Act No. 19 of 2019)',
        'school'      => 'LJ School of Computer Applications',
        'address'     => 'LJ Campus, LJ University Road, Off S.G. Road, Ahmedabad - 382 210',
        'email'       => 'info_ica@ljku.edu.in',
        'phone'       => '9099063417',
        'website'     => 'www.ljku.edu.in',
    ],

    /**
     * Signature lines at the foot of every document. They print blank - the
     * authorised person signs by hand, so no signature is ever reproduced.
     */
    'signatories' => ['HOD', 'Director'],

    /**
     * Print a Credit column after Subject Name, as the university's Semester
     * Performance Report does. Credits come from the teaching scheme.
     */
    'show_credits' => true,

    /**
     * Leave out subjects the student is not registered for instead of
     * printing a row of dashes, as the official marksheet does.
     */
    'hide_not_applicable' => true,

    /**
     * The grade columns, as printed. The key is the column heading, the value
     * is the internal component code (CCE, E for theory, V for practical).
     */
    'result_columns' => [
        'CCE' => 'CCE',
        'TH'  => 'E',
        'PRA' => 'V',
    ],

    /**
     * How those columns are grouped under a spanning heading. Every heading in
     * result_columns must appear here exactly once.
     */
    'result_groups' => [
        'CCE' => ['CCE'],
        'SEE' => ['TH', 'PRA'],
    ],

    /** Order subjects by 'code' (ascending) or 'scheme' (the scheme's own order). */
    'subject_order' => 'code',

    /** Print the class and equivalent percentage beside the SPI. */
    'show_class' => false,

    /** Footnotes printed at the bottom of the result. */
    'result_notes' => [
        '* The above marks are Provisional and are subject to change upon approved by the board of Examination',
        '* The Provisonal Marksheet will be invalid after the issue of final Marksheet',
    ],
];
