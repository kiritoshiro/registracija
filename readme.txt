=== Knygos įgarsinimo registracija ===
Contributors: custom
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 2.2.2

Vienkartinei Elenos Vait knygos „Marijos Sūnaus gyvenimas“ skyrių įgarsinimo registracijai skirtas WordPress įskiepis.

== Naudojimas ==
1. Įdiekite ir aktyvuokite įskiepį.
2. Norimame puslapyje įrašykite shortcode:
   [knygos_igarsinimo_registracija]
3. Administracijoje atidarykite „Įgarsinimo registracijos“.
4. „Skyrių priskyrimas“ skiltyje galima keisti, kuri bendruomenė įgarsins kiekvieną skyrių.
5. „Formos tekstai“ skiltyje galima keisti lankytojui rodomus tekstus, veiksmų pavadinimus, knygos puslapio URL ir PDF URL.
6. Registracijų puslapyje galima matyti visus įrašus, pažymėti, ar santrauka išsiųsta ir ar gautas audio, atlaisvinti rezervaciją ir eksportuoti į Excel (.xlsx). Pažymėjimai išsaugomi automatiškai; taip pat yra bendras išsaugojimo mygtukas.
7. Naujausi įskiepio atnaujinimai iš GitHub rodomi WordPress administracijos skiltyje „Atnaujinimai“.
8. „Google Sheets“ skiltyje galima prijungti vieną Google Sheets dokumentą per Apps Script Web App. Google prisijungimo WordPress pusėje nereikia; sąrankos kodas pateikiamas `google-apps-script/Code.gs`.



== Google Sheets sinchronizacija ==
1. Google Sheets dokumente įkelkite `google-apps-script/Code.gs` turinį per „Extensions → Apps Script“.
2. Apps Script Script properties sukurkite `KIR_SECRET` tokeną.
3. Diegkite Apps Script kaip Web app, vykdomą dokumento savininko vardu, su prieiga „Anyone“, ir nukopijuokite `/exec` URL.
4. WordPress administracijoje atidarykite „Įgarsinimo registracijos → Google Sheets“, įrašykite URL ir tą patį tokeną.
5. Išsaugokite nustatymus ir vieną kartą paleiskite esamų registracijų sinchronizaciją.

Įskiepis į Google Sheets siunčia naujas registracijas, būsenų pakeitimus, atšaukimus ir administratoriaus atlaisvinimus. Jei Google endpoint laikinai nepasiekiamas, vietinė WordPress registracija vis tiek išsaugoma.

== 2.2.2 ==
- Atnaujinimai: pakartotinai patikrinus atnaujinimus skiltyje Pultas → Atnaujinimai (force-check), naujas GitHub leidimas randamas iš karto, nebelaukiant iki 6 valandų talpyklos.

== 2.2.1 ==
- Saugumas: viešos AJAX užklausos atmeta netinkamo tipo reikšmes, saugos kodas (nonce) tikrinamas per check_ajax_referer, o el. pašto adresas išvalomas su sanitize_email.

== 2.2.0 ==
- Registracijų lentelę galima rikiuoti pagal datą ir kitus pagrindinius laukus; pagal nutylėjimą rodomos naujausios registracijos.
- Lentelėje galima redaguoti datą, vardą, el. paštą, bendruomenę, skyrių ir būsenas vienu bendru išsaugojimo mygtuku.
- Pridėtas skyrių dublikatų tikrinimas ir papildomas patvirtinimas prieš keičiant jau rezervuotos registracijos bendruomenę arba skyrių.

== 2.1.1 ==
- Google Sheets Apps Script filtras automatiškai apima visas lentelės kolonas, todėl rikiuojant eilutės nebeatsiskiria.

== 2.1.0 ==
- Pridėta paprasta vienos krypties Google Sheets sinchronizacija per Apps Script Web App.
- Pridėtas Google Sheets nustatymų puslapis su URL, slaptu tokenu ir pradiniu visų registracijų sinchronizavimu.
- Naujos registracijos ir administratoriaus būsenų / atlaisvinimo veiksmai automatiškai perduodami į Google Sheets.
- Pridėtas paruoštas `google-apps-script/Code.gs` ir diegimo aprašas.

== 2.0.0 ==
- Pataisytas skyrių priskyrimų išsaugojimas per atskirą administravimo veiksmą.
- Po išsaugojimo iš naujo apskaičiuojama bendruomenių suvestinė ir viešos formos skyrių sąrašai.
- Prieš keičiant jau rezervuoto skyriaus bendruomenę parodomas aiškus patvirtinimas su konflikto informacija.
- Skyrių priskyrimo puslapyje pridėta bendruomenių suvestinė su visais priskirtais skyriais.

== 1.9.0 ==
- Statuso žymėjimai registracijų sąraše išsaugomi automatiškai juos pakeitus.
- Vietoje atskirų eilučių mygtukų pridėtas vienas bendras „Išsaugoti visus pakeitimus“ mygtukas.

== 1.8.0 ==
- Pridėtas automatinis atnaujinimų tikrinimas iš GitHub leidimų.
- WordPress administracijoje rodomas naujas leidimas ir jo pakeitimų aprašas.
- GitHub archyvo aplankas automatiškai pritaikomas WordPress įskiepio aplankui atnaujinimo metu.

== 1.7.0 ==
- Registracijų sąraše pridėti išsaugomi būsenos žymėjimai „Santrauka išsiųsta“ ir „Atsiuntė audio“.
- Pažymėtos būsenos rodomos žaliai, nepažymėtos – raudonai.
- Pridėtas administravimo puslapis, kuriame galima keisti bendruomenėms priskirtus skyrius.
- Santraukos ir audio būsenos įtrauktos į Excel eksportą.

== 1.6.0 ==
- Pašalintas „Peržiūrėti“ mygtukas prie skyrių.
- Skyrių sąrašas naudojamas tik rezervacijai; knygos skyrių žmogus susiranda pats PDF skaityklėje.
- Nebelieka automatinio PDF puslapio keitimo ar slinkimo iki skaityklės.
- PDF skaityklė savaime užkraunama registracijos puslapio apačioje nuo dokumento pradžios.
- PDF adresas naudojamas tiesiogiai be priverstinių `page`, `pagemode`, `navpanes` ar cache-busting parametrų.


== 1.5.1 ==
* PDF skaityklė vėl prašo naršyklės rodyti dokumento outline / bookmarks skydelį.
* PDF URL gauna versijos užklausos parametrą, kad pakeitus failą tuo pačiu adresu nebūtų naudojama sena naršyklės ar CDN kopija.
* „Atidaryti PDF“ nuoroda taip pat prašo atverti bookmarks skydelį.

== 1.5.0 ==
- Bendruomenės pasirinkimas dabar iškart parodo jai skirtų skyrių sąrašą iš kartu su forma perduotų duomenų; jis nebepriklauso vien nuo admin-ajax atsako.
- Fone vis tiek bandoma pasitikslinti dabartinę rezervacijų būseną serveryje, o galutinė rezervacija visada validuojama DB.
- Sutvarkyta situacija, kai pasirinkus bendruomenę likdavo tekstas „Pirmiausia pasirinkite bendruomenę“.
- PDF skaityklė apribota tokiu pačiu maksimaliu pločiu kaip registracijos forma, kad nebeužliptų ant temos šoninės juostos.
- „Peržiūrėti“ atveria pasirinkto skyriaus pradžios puslapį esamame svetainės PDF; outline lieka pasiekiamas pačioje PDF skaityklėje.
- PDF failas nebepakuojamas į įskiepį. Naudojamas svetainės `wp-content/uploads/Marijos-Sunaus-gyvenimas.pdf`, todėl ZIP failas vėl mažas.
- Atnaujinant iš 1.3/1.4 ankstesnis įskiepyje buvusio PDF URL automatiškai pakeičiamas į svetainėje esančio PDF URL.
- Atšaukus savo pasirinkimą, atlaisvinti skyriai iškart atnaujinami ir naršyklės pusėje.

== 1.4.0 ==
- Po sėkmingos registracijos naršyklėje (localStorage) išsaugomas tik atsitiktinis anoniminis pasirinkimo raktas; vardas ir el. paštas naršyklės talpykloje nesaugomi.
- Grįžus į puslapį tame pačiame įrenginyje parodomi šiuo raktu susieti rezervuoti skyriai.
- Pridėtas mygtukas „Atsisakyti“, kuriuo žmogus gali atšaukti visus su jo naršyklėje išsaugotu raktu susietus pasirinkimus.
- Atšaukus pasirinkimą, DB įrašai ištrinami ir skyriai iš karto vėl tampa laisvi.
- Tas pats naršyklės raktas naudojamas ir vėlesniems papildomiems to paties žmogaus pasirinkimams, todėl vienu „Atsisakyti“ atšaukiami visi jo šiame įrenginyje išsaugoti pasirinkimai.
- Atšaukimas apsaugotas 256 bitų atsitiktiniu slaptu raktu, kurio duomenų bazėje saugoma tik SHA-256 maiša, bei WordPress nonce patikra.
- „Jūsų pasirinkimas“, „Atsisakyti“, patvirtinimo ir sėkmės tekstai redaguojami administracijoje.

== 1.3.0 ==
- Įskiepyje pateikiamas atnaujintas 588 puslapių PDF su outline / žymėmis visiems 87 skyriams.
- Skyrių pradžios puslapiai susieti su atnaujinto PDF outline paskirties puslapiais.
- Pasirinkus bendruomenę kiekvienam skyriui rodoma atskirai: „Pasirinkti“ ir „Peržiūrėti“.
- „Peržiūrėti“ atveria būtent to skyriaus pradžią apačioje esančioje PDF skaityklėje ir nuveda iki skaityklės.
- Rezervuotų skyrių pasirinkti nebegalima, tačiau juos vis tiek galima peržiūrėti PDF skaityklėje.
- PDF skaityklės konteineris nebetaiko WordPress `alignwide`, todėl klauso temos pagrindinio turinio pločio ir nebeužlenda ant šoninės juostos.
- Skaityklės, iframe ir kortelės plotis papildomai apribotas iki 100 % tėvinio turinio konteinerio.
- „Pasirinkti“ ir „Peržiūrėti“ tekstus galima redaguoti administracijoje.

== 1.2.0 ==
- PDF skaityklė perkelta po registracijos forma ir rodoma per visą turinio plotį.
- Bendruomenių pasirinkimai dabar aiškesni, pvz. „Biržai (2) — sk. 1–2“.
- Pasirinkus bendruomenę rodoma, kokie skyriai jai skirti ir kiek jų dar laisva.
- Skyriai rodomi kaip pažymimi pasirinkimai, todėl vienu pateikimu galima rezervuoti vieną arba kelis skyrius.
- Jau rezervuoti skyriai lieka matomi, bet jų pasirinkti negalima.
- Keli pasirinkti skyriai serverio pusėje tikrinami atskirai pagal pasirinktą bendruomenę.
- Kelių skyrių rezervacija vykdoma kaip viena operacija: jei bent vieną skyrių tuo pat metu paima kitas žmogus, ankstesni to pateikimo įrašai atšaukiami.
- Sėkmės pranešime galima naudoti kelių skyrių vietaženklius: {chapters}, {chapter_titles}, {chapter_list}.

== 1.1.0 ==
- Pridėti visi 87 knygos „Marijos Sūnaus gyvenimas“ skyrių pavadinimai.
- Skyriaus pasirinkime rodomas numeris ir pavadinimas.
- Pridėta įterpta PDF skaityklė.
- Pasirinkus skyrių, PDF skaityklė peršoka į to skyriaus pradžios puslapį.
- Pridėtas viso ekrano režimas ir tiesioginė PDF nuoroda.
- Prie aprašymo rodoma nuoroda į https://adventistai.lt/marijos-sunaus-gyvenimas/.
- Skyriaus pavadinimas rodomas administracijoje ir įtraukiamas į Excel eksportą.
- CSV atsarginiam eksportui pridėta apsauga nuo formulės įterpimo.

== Saugumas ==
- Viešos formos užklausos apsaugotos WordPress nonce.
- Savarankiškam atšaukimui naudojamas 256 bitų atsitiktinis raktas; DB saugoma tik jo SHA-256 maiša.
- Visi įvesties duomenys sanitarizuojami ir tikrinami serveryje.
- Bendruomenė ir kiekvienas pasirinktas skyrius tikrinami pagal įskiepyje apibrėžtą sąrašą.
- Duomenų bazėje skyriui taikomas UNIQUE apribojimas, todėl vienas skyrius negali būti rezervuotas du kartus net vienalaikių užklausų atveju.
- Kelių skyrių pateikimas vykdomas transakcijoje, o klaidos atveju yra ir atsarginis įterptų įrašų valymas.
- Administravimo veiksmai reikalauja manage_options teisės ir atskiro nonce.
- Forma turi honeypot lauką nuo paprastų automatinių botų.
- Administracijoje įvedami knygos ir PDF adresai ribojami http/https protokolais.

== Duomenys ==
Lankytojų skaičius nėra saugomas ir nėra rodomas. Saugomi tik:
- vardas ir pavardė;
- el. paštas;
- bendruomenė;
- pasirinktas skyrius (po vieną DB įrašą kiekvienam rezervuotam skyriui);
- registracijos data/laikas;
- anoniminio savarankiško atšaukimo rakto SHA-256 maiša (naujoms registracijoms);
- ar santrauka išsiųsta;
- ar gautas audio.
