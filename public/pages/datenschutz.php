<?php
$pageTitle = 'Datenschutz';
require_once __DIR__ . '/../includes/header.php';
$company   = getSetting('company_name', 'Vireo');
$controller= getSetting('dp_controller', '');          // Verantwortliche Stelle (Anschrift)
$dpo       = getSetting('dp_officer', '');             // Datenschutzbeauftragte/r
$agreement = getSetting('dp_works_agreement', '');     // Betriebsvereinbarung (Bezeichnung/Datum)
$retention = getSetting('dp_retention', '');           // Aufbewahrungs-/Löschfristen
$canManage = hasPerm('manage_settings');
?>
<div class="ph"><div class="phl"><h1>Datenschutz &amp; Verarbeitungshinweise</h1><span class="muted" style="font-size:.82rem">Informationen gem. Art. 13/14 DSGVO zur Verarbeitung personenbezogener Daten in dieser Einsatzplanung</span></div></div>

<?php if($canManage && (!$controller || !$agreement)): ?>
<div class="card" style="border-left:4px solid #f59e0b;margin-bottom:1rem">
  <div class="cb" style="padding:.85rem 1rem">
    <b style="color:#b45309">Hinweis für Administratoren:</b>
    <span class="muted" style="font-size:.86rem">Einige rechtlich relevante Angaben (Verantwortliche Stelle, Datenschutzbeauftragte:r, Betriebsvereinbarung, Löschfristen) sind noch nicht hinterlegt. Diese können unter <a href="<?php echo $_bp;?>einstellungen">Einstellungen</a> gepflegt werden und erscheinen dann automatisch hier. Bitte lassen Sie den Text vor der Vorlage beim Betriebsrat juristisch prüfen.</span>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1rem">
  <div class="cb" style="padding:1rem 1.15rem;line-height:1.55">

    <h3 style="margin:.2rem 0 .5rem">1. Verantwortliche Stelle</h3>
    <p class="muted" style="font-size:.9rem">
      Verantwortlich für die Datenverarbeitung in dieser Anwendung im Sinne der DSGVO ist
      <b><?php echo e($company); ?></b><?php if($controller): ?>:<br><?php echo nl2br(e($controller)); ?><?php else: ?>. Die vollständige Anschrift der verantwortlichen Stelle ist im Impressum bzw. den allgemeinen Datenschutzinformationen des Unternehmens hinterlegt.<?php endif; ?>
    </p>
    <?php if($dpo): ?><p class="muted" style="font-size:.9rem"><b>Datenschutzbeauftragte:r:</b> <?php echo e($dpo); ?></p><?php endif; ?>

    <h3 style="margin:1rem 0 .5rem">2. Zweck der Verarbeitung</h3>
    <p class="muted" style="font-size:.9rem">
      Die Anwendung dient ausschließlich der <b>Personaleinsatz- und Dienstplanung</b>: der Planung von Einsätzen
      und Einsatzbereichen, der Erfassung von Abwesenheiten (Urlaub, Gleitzeit, Krankheit), der Urlaubsverwaltung
      sowie der Abstimmung von Änderungsanträgen zwischen Mitarbeitenden und Vorgesetzten. Eine Verarbeitung zu
      Zwecken der Verhaltens- oder Leistungskontrolle findet nicht statt.
    </p>
    <p class="muted" style="font-size:.9rem">
      Der <b>Planungsassistent</b> erstellt aus diesen Daten Besetzungsvorschläge. Er wertet dafür zurückliegende
      Einsätze aus (Erfahrung bei einem Kunden, Verteilung über Kunden hinweg) und berücksichtigt Abwesenheiten,
      um niemanden einzuplanen, der nicht verfügbar ist. Es findet <b>keine automatisierte Entscheidung im Sinne
      des Art. 22 DSGVO</b> statt: Der Vorschlag ist ein Entwurf, der von einer planungsberechtigten Person geprüft,
      geändert und erst dann veröffentlicht wird. <b>Abwesenheitsgründe</b> – insbesondere Krankmeldungen als
      Gesundheitsdaten nach Art. 9 DSGVO – sind nur für Administratoren sowie für Vorgesetzte des eigenen Teams
      sichtbar; allen übrigen wird lediglich „Abwesend“ angezeigt. Ausdrucke des Assistenten enthalten grundsätzlich keine Abwesenheitsgründe.
    </p>

    <h3 style="margin:1rem 0 .5rem">3. Rechtsgrundlagen</h3>
    <ul class="muted" style="font-size:.9rem;padding-left:1.2rem">
      <li><b>Art. 6 Abs. 1 lit. b DSGVO</b> – Verarbeitung zur Durchführung des Arbeitsverhältnisses.</li>
      <li><b>§ 26 Abs. 1 BDSG</b> – Datenverarbeitung für Zwecke des Beschäftigungsverhältnisses.</li>
      <li><b>Art. 9 Abs. 2 lit. b DSGVO i.V.m. § 26 Abs. 3 BDSG</b> – für Gesundheitsdaten (Krankmeldungen), soweit zur Wahrnehmung arbeitsrechtlicher Pflichten erforderlich.</li>
      <li><b>Betriebsvereinbarung</b><?php if($agreement): ?> „<?php echo e($agreement); ?>"<?php endif; ?> als Erlaubnistatbestand i.S.d. Art. 88 DSGVO / § 26 Abs. 4 BDSG.</li>
    </ul>

    <h3 style="margin:1rem 0 .5rem">4. Kategorien verarbeiteter Daten</h3>
    <ul class="muted" style="font-size:.9rem;padding-left:1.2rem">
      <li><b>Stammdaten:</b> Vor- und Nachname, Benutzername, Rolle, Eintritts-/Austrittsdatum, Ausbildungsberuf, OS-Level.</li>
      <li><b>Planungsdaten:</b> Einsätze, Einsatzbereiche, geplante Tätigkeiten und Bemerkungen.</li>
      <li><b>Abwesenheits- und Urlaubsdaten:</b> Urlaub, Gleitzeit, Sperrzeiten, Resturlaub.</li>
      <li><b>Gesundheitsdaten (besondere Kategorie, Art. 9 DSGVO):</b> Krankmeldungen (mit/ohne Attest, halber Tag). Diese werden ausschließlich im für die Planung erforderlichen Umfang erfasst; medizinische Diagnosen werden nicht gespeichert.</li>
    </ul>

    <h3 style="margin:1rem 0 .5rem">5. Zugriffsberechtigte &amp; Datenminimierung</h3>
    <p class="muted" style="font-size:.9rem">
      Der Zugriff ist rollen- und rechtebasiert beschränkt. <b>Vorgesetzte (Manager/Teamleiter) sehen und bearbeiten
      grundsätzlich nur die Mitarbeitenden, die ihnen zugeordnet sind</b> (Eintrag als Vorgesetzte:r). Der Zugriff auf
      Daten anderer Teams ist technisch unterbunden. Interne Bemerkungen sind nur für ausdrücklich berechtigte Personen
      sichtbar. Eine Übermittlung an Dritte außerhalb des Unternehmens erfolgt nicht.
    </p>

    <h3 style="margin:1rem 0 .5rem">6. Speicherdauer &amp; Löschung</h3>
    <p class="muted" style="font-size:.9rem">
      <?php if($retention): ?><?php echo nl2br(e($retention)); ?><?php else: ?>
      Personenbezogene Daten werden nur so lange gespeichert, wie es für die Einsatzplanung und zur Erfüllung
      gesetzlicher Aufbewahrungspflichten erforderlich ist. Nach Austritt werden Mitarbeitende ab dem Folgemonat in
      der laufenden Planung ausgeblendet; eine vollständige Löschung erfolgt nach den unternehmensintern festgelegten
      Fristen. Die konkreten Löschfristen sind durch die verantwortliche Stelle festzulegen.<?php endif; ?>
    </p>

    <h3 style="margin:1rem 0 .5rem">7. Protokollierung</h3>
    <p class="muted" style="font-size:.9rem">
      Sicherheitsrelevante Ereignisse (z.&nbsp;B. Anmeldeversuche) werden in aggregierter, <b>anonymisierter</b> Form
      protokolliert. In der Oberfläche werden keine personenbezogenen Protokolldaten, Benutzernamen oder IP-Adressen
      angezeigt. Eine individuelle Verhaltens- oder Leistungskontrolle findet nicht statt.
    </p>

    <h3 style="margin:1rem 0 .5rem">8. Datensicherheit</h3>
    <p class="muted" style="font-size:.9rem">
      Es werden technische und organisatorische Maßnahmen getroffen, u.&nbsp;a. verschlüsselte Sicherungen,
      Schutz vor websiteübergreifenden Anfragen (CSRF), restriktive Sicherheits-Header, Sitzungsabsicherung
      sowie Begrenzung von Anmeldeversuchen.
    </p>

    <h3 style="margin:1rem 0 .5rem">9. Rechte der betroffenen Personen</h3>
    <p class="muted" style="font-size:.9rem">
      Beschäftigte haben das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17),
      Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21).
      Zudem besteht ein Beschwerderecht bei der zuständigen Datenschutzaufsichtsbehörde. Anfragen sind an die
      verantwortliche Stelle bzw. die/den Datenschutzbeauftragte:n zu richten.
    </p>

    <h3 style="margin:1rem 0 .5rem">10. Beteiligung des Betriebsrats</h3>
    <p class="muted" style="font-size:.9rem">
      Die Einführung und Anwendung dieses Systems unterliegt der Mitbestimmung des Betriebsrats nach
      <b>§ 87 Abs. 1 Nr. 6 BetrVG</b>, da es sich um eine technische Einrichtung handelt, die grundsätzlich zur
      Überwachung von Verhalten oder Leistung geeignet sein kann. Die zulässigen Verarbeitungszwecke und Zugriffe
      werden in einer Betriebsvereinbarung geregelt<?php if($agreement): ?> („<?php echo e($agreement); ?>")<?php endif; ?>.
    </p>

  </div>
</div>

<p class="muted" style="font-size:.78rem;text-align:center;margin-bottom:2rem">
  Dieser Text ist eine an die Anwendung angepasste Vorlage und ersetzt keine individuelle Rechtsberatung.
  Bitte vor Veröffentlichung durch die verantwortliche Stelle und den Betriebsrat prüfen lassen.
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
