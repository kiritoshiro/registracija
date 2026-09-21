# Google Sheets Apps Script

Šis katalogas pateikia paprastą vienos krypties ryšį: WordPress registracijos siunčiamos į vieną Google Sheets dokumentą be Google prisijungimo WordPress pusėje.

## Įdiegimas

1. Sukurkite arba pasirinkite Google Sheets dokumentą.
2. Dokumente atidarykite **Extensions → Apps Script**.
3. Į Apps Script įklijuokite `Code.gs` turinį ir išsaugokite.
4. Apps Script **Project Settings → Script properties** sukurkite:
   - `KIR_SECRET` — ilgą atsitiktinį tokeną;
   - pasirinktinai `KIR_SHEET_NAME` — lapo pavadinimą, numatyta reikšmė `Registracijos`;
   - pasirinktinai `KIR_SPREADSHEET_ID` — dokumento ID, jei Apps Script nėra prijungtas prie dokumento.
5. Pasirinkite **Deploy → New deployment → Web app**.
6. Nustatykite **Execute as: Me** ir prieigą **Who has access: Anyone**, tada nukopijuokite URL, kuris baigiasi `/exec`.
7. WordPress administracijoje atidarykite **Įgarsinimo registracijos → Google Sheets**.
8. Įrašykite Web App URL, tą patį tokeną, įjunkite sinchronizaciją ir išsaugokite.
9. Vieną kartą paspauskite **Sinchronizuoti visas registracijas dabar**.

Naujos registracijos, būsenų pakeitimai, atšaukimai ir administratoriaus atlaisvintos rezervacijos bus siunčiamos automatiškai. Jei Apps Script laikinai nepasiekiamas, WordPress registracija vis tiek išsaugoma vietinėje duomenų bazėje.

Tokenas saugomas tik WordPress nustatymuose ir Apps Script Script properties. Jo neskelbkite viešai.
