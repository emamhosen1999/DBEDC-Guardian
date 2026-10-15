{{-- House PDF base for every attendance report: Liberation Sans Narrow (Arial Narrow metrics), DejaVu Sans for the symbols it lacks. --}}
@font-face { font-family: 'RegNarrow'; font-weight: normal; font-style: normal; src: url('{{ $fontDir }}/LiberationSansNarrow-Regular.ttf') format('truetype'); }
@font-face { font-family: 'RegNarrow'; font-weight: bold; font-style: normal; src: url('{{ $fontDir }}/LiberationSansNarrow-Bold.ttf') format('truetype'); }
@font-face { font-family: 'RegSymbols'; font-weight: normal; font-style: normal; src: url('{{ $fontDir }}/DejaVuSans.ttf') format('truetype'); }
@font-face { font-family: 'RegSymbols'; font-weight: bold; font-style: normal; src: url('{{ $fontDir }}/DejaVuSans-Bold.ttf') format('truetype'); }
@page { margin: 0.4in 0.4in 0.5in 0.4in; }
body { margin: 0; font-family: 'RegNarrow', sans-serif; color: #000; }
.s { font-family: 'RegSymbols', 'RegNarrow', sans-serif; }
table.rep { border-collapse: collapse; width: 100%; }
table.rep td, table.rep th { font-family: 'RegNarrow', sans-serif; padding: 1.5pt 3pt; border: 0.5pt solid #000; font-size: 8pt; line-height: 1.1; }
table.rep th { font-weight: bold; text-align: center; border-top: 1.5pt solid #000; border-bottom: 1.5pt solid #000; }
.frame { border: 1.5pt solid #000; }
.rep-title td { text-align: center; border: 0; padding: 0; }
.rep-sign { width: 100%; border-collapse: collapse; margin-top: 0; }
.rep-sign td { height: 40pt; vertical-align: top; font-size: 9pt; border: 0.5pt solid #000; padding: 2pt 3pt; }
.rep-key { font-size: 8pt; padding: 3pt; border: 0.5pt solid #000; }
