export interface GradeBand {
  min: number;
  point: number;
  grade: string;
}

export interface ClassBand {
  min: number;
  label: string;
}

export interface SlotDef {
  label: string;
  hint: string;
  kind: 'marks' | 'credits';
}

export interface DocumentDef {
  type: 'grades' | 'marks';
  title: string;
  label: string;
  spi?: boolean;
}

export interface FallbackSubject {
  code: string;
  credits: number;
  name: string;
  components: string[];
}

export interface SemesterMeta {
  label: string;
  course_name: string;
  passing_year: string;
}

export const config = {
  semesters: {
    '1': { label: '1st Semester', course_name: 'Master of Computer Application (Integrated)', passing_year: '2026' },
    '2': { label: '2nd Semester', course_name: 'Master of Computer Application (Integrated)', passing_year: '2026' },
    '3': { label: '3rd Semester', course_name: 'Master of Computer Application (Integrated)', passing_year: '2026' },
    '4': { label: '4th Semester', course_name: 'Master of Computer Application (Integrated)', passing_year: '2026' },
  } as Record<string, SemesterMeta>,

  fallback_subjects: {
    '4': [
      { code: '150120402', credits: 4, name: 'Operating Systems', components: ['E', 'CCE'] },
      { code: '150120401', credits: 4, name: 'Programming in Python', components: ['E', 'CCE'] },
      { code: '150120408', credits: 2, name: 'Programming in Python -Lab', components: ['V', 'CCE'] },
      { code: '150120404', credits: 2, name: 'Basic Statistics using R -Lab', components: ['V', 'CCE'] },
      { code: '150120409', credits: 2, name: 'Unified Modeling language - Lab', components: ['V', 'CCE'] },
      { code: '150120412', credits: 4, name: 'Web Application Development using Java Framework (Spring)', components: ['V', 'CCE'] },
      { code: '150120413', credits: 4, name: 'Web Application Development using Laravel ( Laravel )', components: ['V', 'CCE'] },
      { code: '150120410', credits: 2, name: 'Social Media Marketing - SMM', components: ['E'] },
      { code: '150120411', credits: 2, name: 'Yoga and Wellness', components: ['E'] },
    ] as FallbackSubject[],
  } as Record<string, FallbackSubject[]>,

  credit_unit: 25,

  grade_scale: [
    { min: 95, point: 10.0, grade: 'O+++' },
    { min: 90, point: 9.5, grade: 'O++' },
    { min: 85, point: 9.0, grade: 'O+' },
    { min: 80, point: 8.5, grade: 'O' },
    { min: 75, point: 8.0, grade: 'A++' },
    { min: 70, point: 7.5, grade: 'A+' },
    { min: 65, point: 7.0, grade: 'A' },
    { min: 60, point: 6.5, grade: 'B++' },
    { min: 55, point: 6.0, grade: 'B+' },
    { min: 50, point: 5.5, grade: 'B' },
    { min: 45, point: 5.0, grade: 'C' },
    { min: 40, point: 4.5, grade: 'D' },
    { min: 0, point: 0.0, grade: 'F' },
  ] as GradeBand[],

  class_bands: [
    { min: 7.5, label: 'First class with distinction' },
    { min: 6.5, label: 'First class' },
    { min: 5.5, label: 'Higher Second Class' },
    { min: 5.0, label: 'Second class' },
    { min: 4.0, label: 'Pass class' },
    { min: 0, label: 'Fail' },
  ] as ClassBand[],

  fail_grade: 'F',
  show_spi: true,

  component_labels: { E: 'E', V: 'V', CCE: 'CCE', M: 'M' } as Record<string, string>,

  component_overrides: {} as Record<string, string>,

  slots: {
    internal: { label: 'Internal (CCE)', hint: 'CCE marks for every subject', kind: 'marks' },
    theory: { label: 'External theory (E)', hint: 'End-semester theory marks', kind: 'marks' },
    viva: { label: 'External practical (V)', hint: 'Viva and practical marks', kind: 'marks' },
    scheme: { label: 'Teaching scheme', hint: 'Subject codes and credits', kind: 'credits' },
  } as Record<string, SlotDef>,

  credit_column: null as string | null,

  institute: 'L J Institute of Computer Applications',

  documents: {
    grades: { type: 'grades', title: 'Provisional Result', label: 'Official grade sheet', spi: true },
    marks: { type: 'marks', title: 'Statement of Marks', label: 'Marks sheet' },
  } as Record<string, DocumentDef>,

  letterhead: {
    logo: 'assets/logo.png',
    university: 'Lok Jagruti Kendra University',
    tagline: 'University with a Difference',
    established: '(Lok Jagruti Kendra University Established by Gujarat Act No. 19 of 2019)',
    school: 'LJ School of Computer Applications',
    address: 'LJ Campus, LJ University Road, Off S.G. Road, Ahmedabad - 382 210',
    email: 'info_ica@ljku.edu.in',
    phone: '9099063417',
    website: 'www.ljku.edu.in',
  },

  signatories: ['HOD', 'Director'],
  show_credits: true,
  hide_not_applicable: true,

  result_columns: { CCE: 'CCE', TH: 'E', PRA: 'V' } as Record<string, string>,
  result_groups: { CCE: ['CCE'], SEE: ['TH', 'PRA'] } as Record<string, string[]>,

  subject_order: 'code' as 'code' | 'scheme',
  show_class: false,

  result_notes: [
    '* The above marks are Provisional and are subject to change upon approved by the board of Examination',
    '* The Provisonal Marksheet will be invalid after the issue of final Marksheet',
  ],
};

export type Config = typeof config;
