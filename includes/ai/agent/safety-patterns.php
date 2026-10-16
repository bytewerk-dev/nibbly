<?php
/**
 * Word lists and patterns of the agent's safety check (includes/ai/agent/safety.php).
 *
 * All patterns work on folded text: lowercase, accents removed, everything that is not a letter or digit
 * replaced by one space (nibblyMapNormalize()). Words are therefore written without accents ("loschen" for
 * "löschen"). The lists cover the nine admin languages (cs, de, en, es, fr, it, pl, pt, tr); they are a
 * heuristic first line, not a moderation service. Extend a list here, never inline in the check.
 */

$word = static fn(array $words): string => '(?:' . implode('|', array_map(static fn($w) => str_replace(' ', '\s+', preg_quote($w, '/')), $words)) . ')';

// Verbs that ask somebody to hand something over.
$handOver = $word([
    'enter', 'type in', 'confirm', 'verify', 'send', 'provide', 'submit', 'give', 'share', 'update', 'reply with', 'tell us',
    'eingeben', 'gib', 'geben', 'gebt', 'bestatige', 'bestatigen', 'verifiziere', 'verifizieren', 'sende', 'senden', 'schick', 'schicke', 'schicken', 'teile', 'teilen', 'ubermittle', 'ubermitteln', 'aktualisiere', 'aktualisieren', 'nenne', 'nennen',
    'saisissez', 'confirmez', 'verifiez', 'envoyez', 'fournissez', 'communiquez', 'indiquez',
    'introduce', 'introduzca', 'confirma', 'confirme', 'verifica', 'verifique', 'envia', 'envie', 'proporciona', 'proporcione', 'indica',
    'inserisci', 'inserisca', 'conferma', 'invia', 'invii', 'fornisci', 'insira', 'forneca', 'informe',
    'podaj', 'potwierdz', 'zweryfikuj', 'wyslij', 'zadejte', 'potvrdte', 'overte', 'posleste', 'girin', 'dogrulayin', 'gonderin', 'paylasin',
]);
// Secrets that nobody may ask for on a website.
$secrets = $word([
    'password', 'passwort', 'kennwort', 'passwd', 'pin', 'tan', 'cvv', 'cvc', 'security code', 'sicherheitscode', 'seed phrase', 'recovery phrase', 'private key', 'geheimzahl',
    'mot de passe', 'code secret', 'contrasena', 'clave secreta', 'senha', 'haslo', 'heslo', 'sifre', 'sifrenizi', 'codice di sicurezza', 'codigo de seguranca',
]);
// Payment and identity data.
$payment = $word([
    'credit card', 'kreditkarte', 'kreditkartennummer', 'kartennummer', 'iban', 'bic', 'bank details', 'bankdaten', 'kontodaten', 'zugangsdaten', 'login details', 'social security', 'sozialversicherungsnummer', 'ssn',
    'carte bancaire', 'numero de carte', 'tarjeta de credito', 'numero de tarjeta', 'carta di credito', 'numero della carta', 'cartao de credito', 'numero do cartao', 'numer karty', 'cislo karty', 'kart numarasi',
]);
// Words that make a request for data urgent or threatening (what a phishing text does).
$urgency = $word([
    'urgent', 'urgently', 'immediately', 'within 24 hours', 'suspended', 'locked', 'will be closed', 'will be deleted', 'unauthorized', 'unusual activity',
    'dringend', 'sofort', 'umgehend', 'innerhalb von 24 stunden', 'gesperrt', 'deaktiviert', 'verdachtige aktivitat', 'unbefugt',
    'urgent', 'immediatement', 'suspendu', 'bloque', 'urgente', 'inmediatamente', 'suspendida', 'bloqueada', 'urgentemente', 'subito', 'sospeso', 'bloccato', 'pilne', 'natychmiast', 'zablokowane', 'okamzite', 'zablokovan', 'acil', 'hemen', 'askiya alindi',
]);
// Things that are sold or shown and are illegal in all nine markets.
$illegalGoods = $word([
    'cocaine', 'kokain', 'heroin', 'crystal meth', 'fentanyl', 'mdma', 'fake passports', 'forged passports', 'fake id cards', 'gefalschte ausweise', 'gefalschte reisepasse', 'falsche ausweise', 'stolen credit cards', 'stolen cards', 'gestohlene kreditkarten', 'gestohlene daten',
    'illegal weapons', 'illegale waffen', 'waffen ohne waffenschein', 'ak 47', 'cocaina', 'cocaine', 'heroina', 'faux papiers', 'documents falsifies', 'documentos falsos', 'documenti falsi', 'falszywe dokumenty', 'falesne doklady', 'sahte pasaport',
]);
// Targets of hate. Used together with a call for violence or expulsion.
$groups = $word([
    'jews', 'juden', 'muslims', 'muslime', 'migranten', 'immigrants', 'fluchtlinge', 'refugees', 'blacks', 'schwarze', 'gays', 'schwule', 'auslander', 'foreigners', 'women', 'frauen', 'roma', 'sinti', 'asylanten', 'christians', 'christen',
    'juifs', 'musulmans', 'judios', 'musulmanes', 'ebrei', 'musulmani', 'judeus', 'muculmanos', 'zydzi', 'muzulmanie', 'zide', 'muslimove', 'yahudiler', 'musluman',
]);
$violence = $word([
    'kill', 'kills', 'killing', 'murder', 'murders', 'exterminate', 'gas', 'burn', 'hang', 'shoot', 'eradicate', 'deport', 'drive out', 'wipe out',
    'toten', 'tote', 'ermorden', 'erschiessen', 'ausrotten', 'vernichten', 'vergasen', 'verbrennen', 'aufhangen', 'abschieben', 'raus',
    'tuer', 'exterminer', 'matar', 'exterminar', 'uccidere', 'sterminare', 'zabic', 'zabijac', 'zabit', 'oldur',
]);

// Verbs and quantifiers of "remove everything" in the nine languages. A verb and an "all" within 40 characters.
$destroyVerbs = $word([
    'delete', 'remove', 'erase', 'wipe', 'clear', 'empty', 'purge', 'destroy', 'drop', 'get rid of', 'hide', 'trash', 'nuke',
    'losch', 'loschen', 'losche', 'entfern', 'entferne', 'entfernen', 'streich', 'streiche', 'streichen', 'leere', 'leeren', 'zerstor', 'zerstore', 'zerstoren', 'vernicht', 'vernichte', 'wisch', 'wische', 'radier', 'radiere', 'blend', 'blende', 'ausblenden', 'verberg', 'verberge', 'verstecke', 'verstecken',
    'supprime', 'supprimer', 'supprimez', 'efface', 'effacer', 'enleve', 'enlever', 'vide', 'vider', 'detruis', 'detruire', 'cacher',
    'elimina', 'eliminar', 'borra', 'borrar', 'quita', 'quitar', 'vacia', 'vaciar', 'destruye', 'oculta', 'ocultar',
    'cancella', 'cancellare', 'rimuovi', 'rimuovere', 'svuota', 'svuotare', 'distruggi', 'nascondi', 'nascondere',
    'apague', 'apagar', 'exclua', 'excluir', 'remova', 'remover', 'esvazie', 'esvaziar', 'destrua', 'oculte',
    'usun', 'usunac', 'skasuj', 'wyczysc', 'wymaz', 'zniszcz', 'ukryj',
    'smaz', 'smazat', 'odstran', 'odstranit', 'vymaz', 'vymazat', 'znic', 'skryj',
    'sil', 'silin', 'kaldir', 'kaldirin', 'temizle', 'yok', 'gizle',
]);
$allQuantifiers = $word([
    'everything', 'all content', 'all text', 'all texts', 'all sections', 'all pages', 'all items', 'all posts', 'all news', 'all events', 'all images', 'all of it', 'all of the content', 'the whole page', 'the whole site', 'the whole website', 'the entire page', 'the entire site', 'the entire website', 'entire content', 'whole content', 'every page', 'every section', 'every post', 'complete page', 'complete site', 'complete website',
    'alles', 'alle inhalte', 'alle texte', 'alle seiten', 'alle abschnitte', 'alle sektionen', 'alle eintrage', 'alle beitrage', 'alle news', 'alle termine', 'alle bilder', 'samtliche inhalte', 'samtliche seiten', 'samtliche texte', 'die ganze seite', 'die gesamte seite', 'die komplette seite', 'die ganze website', 'die gesamte website', 'die komplette website', 'die ganze webseite', 'die komplette webseite', 'die gesamte webseite', 'den ganzen inhalt', 'den gesamten inhalt', 'komplette seite', 'samtliches',     'tout', 'toute la page', 'tout le site', 'tous les contenus', 'toutes les pages', 'tous les textes', 'toutes les sections', 
    'todo', 'toda la pagina', 'todo el sitio', 'todo el contenido', 'todas las paginas', 'todos los textos', 'todas las secciones', 
    'tutto', 'tutta la pagina', 'tutto il sito', 'tutto il contenuto', 'tutte le pagine', 'tutti i testi', 'tutte le sezioni', 
    'tudo', 'toda a pagina', 'todo o site', 'todo o conteudo', 'todas as paginas', 'todos os textos', 'todas as secoes', 
    'wszystko', 'cala strone', 'cala witryne', 'cala zawartosc', 'wszystkie strony', 'wszystkie teksty', 'wszystkie sekcje', 
    'vsechno', 'cely web', 'celou stranku', 'cely obsah', 'vsechny stranky', 'vsechny texty', 'vsechny sekce', 
    'hepsini', 'tumunu', 'butun sayfayi', 'tum sayfalari', 'tum icerigi', 'tum metinleri', 'tum bolumleri', 
]);

// "everything else" is the opposite of "everything".
$butNot = '(?!\\s+(?:else|other|otherwise|remaining|andere|anderen|anderes|ubrige|ubrigen|weitere|weiteren|sonst|autre|autres|otro|otros|altro|altri|outro|outros|inne|jine|diger))';

return [
    // Content categories. level: 'block' stops the change set, 'caution' asks for an extra confirmation.
    // Each pattern is tried on folded text ("folded") or on the lowercase raw text including markup ("raw").
    'categories' => [
        'secret_request' => [
            'level' => 'block',
            'folded' => ['/\b' . $handOver . '\b(?:\s+\w+){0,5}\s+\b' . $secrets . '\b/'],
            // The same words without urgency or a link are only noted: a text about how to log in is not phishing.
            'soften_unless' => ['/\b' . $urgency . '\b/', '/https?\s+\w+\s+\w/'],
            'soft_level' => 'caution',
        ],
        'payment_request' => [
            'level' => 'caution',
            'folded' => ['/\b' . $handOver . '\b(?:\s+\w+){0,5}\s+\b' . $payment . '\b/'],
            'escalate_if' => ['/\b' . $urgency . '\b/'],
        ],
        'phishing' => [
            'level' => 'block',
            'folded' => [
                '/\bverify\s+your\s+(?:account|identity|wallet|login|payment)\b/', '/\bconfirm\s+your\s+(?:account|identity|bank|payment)\b/',
                '/\byour\s+account\s+(?:has\s+been|will\s+be|is)\s+(?:suspended|locked|closed|restricted|limited)\b/',
                '/\b(?:ihr|dein)\s+konto\s+(?:wurde|wird|ist)\s+(?:gesperrt|deaktiviert|eingeschrankt)\b/', '/\bkonto\s+(?:verifizieren|bestatigen)\s+(?:um|damit|sonst)\b/',
                '/\bvotre\s+compte\s+(?:a\s+ete|sera|est)\s+(?:suspendu|bloque|limite)\b/', '/\btu\s+cuenta\s+(?:ha\s+sido|sera|esta)\s+(?:suspendida|bloqueada|limitada)\b/',
                '/\bil\s+tuo\s+account\s+(?:e\s+stato|sara|e)\s+(?:sospeso|bloccato|limitato)\b/', '/\bsua\s+conta\s+(?:foi|sera|esta)\s+(?:suspensa|bloqueada|limitada)\b/',
                '/\btwoje\s+konto\s+(?:zostalo|zostanie|jest)\s+(?:zablokowane|zawieszone)\b/', '/\bvas\s+ucet\s+(?:byl|bude|je)\s+(?:zablokovan|pozastaven)\b/', '/\bhesabiniz\s+(?:askiya|kilitlendi|engellendi)\b/',
                '/\b(?:phishing|spoofing)\s+(?:page|seite|site|kit|login)\b/',
            ],
        ],
        'scam' => [
            'level' => 'block',
            'folded' => [
                '/\b(?:double|doubles|multiply|verdoppel\w*|verdopple\w*|vervielfach\w*)\s+(?:\w+\s+){0,2}(?:bitcoin|btc|crypto|krypto|eth|geld|money|investment)\b/',
                '/\bguaranteed\s+(?:profit|profits|returns?|income|winnings?)\b/', '/\bgarantierte\s+(?:rendite|gewinne?|einnahmen)\b/', '/\bgarantiert\s+(?:\w+\s+){0,2}(?:reich|gewinn|rendite)\b/',
                '/\b(?:advance|upfront)\s+fee\b/', '/\b(?:vorauszahlung|vorschussgebuhr|bearbeitungsgebuhr\s+vorab|gebuhr\s+vorab)\b/',
                '/\b(?:you\s+have\s+won|you\s+won|you\s+are\s+the\s+winner|sie\s+haben\s+gewonnen|du\s+hast\s+gewonnen|vous\s+avez\s+gagne|has\s+ganado|hai\s+vinto|voce\s+ganhou|wygrales|vyhral\w*\s+jste|kazandiniz)\b/',
                '/\b(?:send|transfer|wire|sende|uberweise|schicke|envoyez|envia|invia|envie|wyslij)\s+(?:\w+\s+){0,3}(?:bitcoin|btc|gift\s?cards?|geschenkkarten|western\s+union|moneygram|paysafecard)\b/',
                '/\b(?:pyramid\s+scheme|ponzi|schneeballsystem|pyramidensystem|enkeltrick|romance\s+scam|liebesbetrug)\b/',
                '/\b(?:get\s+rich\s+quick|schnell\s+reich|ohne\s+risiko\s+(?:\w+\s+){0,2}(?:gewinn|rendite|reich))\b/',
            ],
        ],
        'malware' => [
            'level' => 'block',
            'folded' => [
                '/\b(?:download|install|run|open|herunterladen|installieren|ausfuhren|offnen|telecharger|installer|descargar|instalar|scaricare|installare|baixar|pobierz|stahnout|indir)\s+(?:\w+\s+){0,5}(?:crack|keygen|warez|exe|scr|bat|msi|apk|dmg|jar|vbs|ps1)\b/',
                '/\b(?:keylogger|ransomware|trojan\w*|botnet|rootkit|cryptominer|crypto\s+miner|credential\s+stealer|wallet\s+drainer|crypto\s+drainer|exploit\s+kit)\b/',
            ],
            'raw' => [
                '/<\s*script\b/i', '/<\s*iframe\b/i', '/<\s*(?:object|embed|applet|meta\s+http-equiv)\b/i', '/\bjavascript\s*:/i', '/\bvbscript\s*:/i', '/\bdata\s*:\s*text\/html/i',
                '/\bon(?:error|load|click|mouseover|focus|submit)\s*=/i', '/\bdocument\s*\.\s*(?:cookie|write|location)/i', '/\beval\s*\(/i', '/\bfromcharcode\b/i', '/\batob\s*\(/i',
                '/\b[a-z0-9._-]+\.(?:exe|scr|bat|msi|apk|vbs|ps1|jar|dmg|pkg)\b/i',
            ],
        ],
        'hate' => [
            'level' => 'block',
            'folded' => [
                '/\b' . $violence . '\s+(?:\w+\s+){0,2}' . $groups . '\b/', '/\b' . $groups . '\s+(?:\w+\s+){0,2}(?:raus|abschieben|vergasen|ausrotten|verbrennen)\b/',
                '/\b(?:sieg\s+heil|heil\s+hitler|white\s+power|rassenschande|lugenpresse|umvolkung|great\s+replacement|race\s+war|rassenkrieg|totet\s+alle)\b/',
                '/\bauslander\s+raus\b/', '/\b(?:untermensch\w*|ungeziefer)\b/',
            ],
        ],
        'adult' => [
            'level' => 'block',
            'folded' => [
                '/\b(?:porn|porno|pornos|pornografie|pornographie|pornographic|pornografia|pornografico|pornografia|xxx|hardcore\s+sex|sexvideos?|sex\s*cams?|sexcams?|escort\s+(?:service|agentur)|blowjob|nacktbilder|nude\s+(?:photos|pics|pictures)|onlyfans\s+leaks?|erotic\s+(?:videos|chat)|erotikchat|cam\s*girls?|camgirls?)\b/',
                '/\b(?:child|kinder|kids?|minor|teen)\s*(?:porn|porno|sex)\w*\b/', '/\bkinderpornografie\b/',
            ],
        ],
        'illegal' => [
            'level' => 'block',
            'folded' => [
                '/\b(?:buy|order|sell|kaufen|bestellen|verkaufen|kaufe|bestelle|verkaufe|acheter|comprar|vender|comprare|vendere|kupic|koupit|satin)\s+(?:\w+\s+){0,4}' . $illegalGoods . '\b/',
                '/\b' . $illegalGoods . '\s+(?:for\s+sale|zu\s+verkaufen|online\s+kaufen|bestellen|a\s+la\s+venta|in\s+vendita)\b/',
            ],
        ],
        // Text that tries to steer a language model. In site content it is a sign of a manipulated source.
        'injection_echo' => [
            'level' => 'caution',
            'folded' => [
                '/\bignore\s+(?:all\s+|any\s+|the\s+)?(?:previous|prior|above|earlier)\s+(?:instructions|prompts?|rules)\b/', '/\bdisregard\s+(?:all\s+|the\s+)?(?:previous|prior|above)\b/',
                '/\b(?:ignoriere|ignorieren)\s+(?:alle\s+)?(?:vorherigen|bisherigen|obigen)\s+(?:anweisungen|regeln)\b/', '/\byou\s+are\s+now\s+(?:in\s+)?(?:developer|dan|jailbreak|god)\s+mode\b/',
                '/\bsystem\s+prompt\b/', '/\bnew\s+instructions\s*:/', '/\bas\s+an\s+ai\s+(?:language\s+)?model\b/',
            ],
        ],
    ],

    // Short link services and other hosts that hide where a link goes.
    'shorteners' => ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'is.gd', 'cutt.ly', 'rb.gy', 'ow.ly', 'buff.ly', 'shorturl.at', 'tiny.cc', 'rebrand.ly', 'lnkd.in', 'adf.ly', 'bl.ink', 'v.gd', 'u.to', 'qr.net'],

    // Requests (what a person asks the agent for). category => pattern list on folded text.
    'requests' => [
        'mass_delete' => [
            '/\b' . $destroyVerbs . '\b(?:\s+\w+){0,6}\s+\b' . $allQuantifiers . '\b' . $butNot . '/',
            '/\b' . $allQuantifiers . '\b' . $butNot . '(?:\s+\w+){0,6}\s+\b' . $destroyVerbs . '\b/',
        ],
        // Asking for the creation of criminal material: a creation verb near a term (a text about phishing is allowed).
        'create_crime' => [
            'verbs' => $word([
                'create', 'build', 'make', 'write', 'set up', 'generate', 'design', 'prepare', 'draft', 'add',
                'erstelle', 'erstellen', 'baue', 'bauen', 'schreibe', 'schreiben', 'mach', 'mache', 'entwirf', 'richte', 'formuliere', 'fuge', 'erzeuge',
                'cree', 'creer', 'ecris', 'ecrire', 'escribe', 'crea', 'crear', 'scrivi', 'scrivere', 'crie', 'criar', 'escreva', 'napisz', 'stworz', 'vytvor', 'napis', 'olustur', 'yaz',
            ]),
            'terms' => $word([
                'phishing', 'fake login', 'fake shop', 'fakeshop', 'fake rechnung', 'fake invoice', 'scam', 'betrug', 'betrugsseite', 'schneeballsystem', 'ponzi', 'malware', 'ransomware', 'keylogger', 'trojan', 'skimmer', 'identity theft', 'identitatsdiebstahl',
                'porn', 'porno', 'pornografie', 'hate speech', 'hetze', 'volksverhetzung', 'drug shop', 'drogenshop', 'kinderpornografie',
            ]),
        ],
    ],

    // Words in a request that make it a question about the subject, not an order (screening is skipped for "wie erkenne ich phishing").
    'request_information' => '/\b(?:how\s+(?:do|can|to)\s+(?:i\s+)?(?:recogni[sz]e|spot|detect|avoid|protect)|what\s+is|was\s+ist|wie\s+(?:erkenne|schutze|vermeide)|qu\s+est\s+ce|que\s+es|cos\s+e|o\s+que\s+e|co\s+to\s+jest|co\s+je|nedir)\b/',
];
