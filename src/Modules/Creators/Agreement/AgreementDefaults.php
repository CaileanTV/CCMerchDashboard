<?php
/**
 * The draft of the creators' agreement the plugin ships.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Agreement;

defined( 'ABSPATH' ) || exit;

/**
 * The agreement in Danish, written from the company's earlier cooperation
 * agreement (the owner 2026-11-06, docs/features/creator-signup.md). It is a
 * draft, not legal advice: the administrator reviews it before publishing.
 * The placeholders [COMPANY], [ADDRESS], [CVR] and [EMAIL] are filled from
 * the company details when the text is shown, so no company details are
 * stored in the plugin.
 */
final class AgreementDefaults {

	/**
	 * The draft text (HTML).
	 */
	public static function text(): string {
		return <<<'HTML'
<h2>Samarbejdsaftale for creators hos [COMPANY]</h2>

<h3>1. Parter</h3>
<p>Aftalen indgås mellem dig som creator, med det navn og den e-mail, du opretter dig med på MitCCMerch, og [COMPANY], [ADDRESS], CVR-nr. [CVR] ("vi").</p>

<h3>2. Formål</h3>
<p>2.1 Du kan få produceret og solgt merchandise i dit navn i vores webshop.<br>
2.2 Du henviser dine følgere til din side i webshoppen.<br>
2.3 Vi står for produktion, salg, forsendelse og kundeservice.</p>

<h3>3. Oprettelse og go-live</h3>
<p>3.1 Du skal være fyldt 18 år.<br>
3.2 Du kan forberede din shop på MitCCMerch, men intet vises i webshoppen, før vi har godkendt din anmodning om at gå live. Afviser vi, får du en begrundelse.</p>

<h3>4. Designs og rettigheder</h3>
<p>4.1 Alle designs godkendes af os, før de kommer i webshoppen. Vi kan afvise eller fjerne et design, fx hvis det kan krænke andres rettigheder, er stødende eller ikke kan produceres.<br>
4.2 Du bekræfter, at du har rettighederne til alt, du uploader (billeder, logoer og tekster), eller tilladelse fra den, der har dem.<br>
4.3 Du giver os ret til at bruge dine designs til at producere, vise og markedsføre dine produkter, så længe aftalen gælder, og til at gøre ordrer færdige.<br>
4.4 Krænker et design, du har uploadet, andres rettigheder, holder du os skadesløs for krav fra tredjemand.<br>
4.5 Vi har ansvaret for produkterne, leveringen og kundeservicen. Vi holder dig fri for krav fra kunder og myndigheder om vores produkter og ydelser.</p>

<h3>5. Din fortjeneste</h3>
<p>5.1 Din fortjeneste er et tillæg til vores pris på produktet. Tillægget er en procentsats eller et beløb, som vi aftaler med dig, eller som du selv sætter i produktdesigneren.<br>
5.2 Er du ikke momsregistreret, trækkes 20 % af fortjenesten, og resten udbetales. Er du momsregistreret, udbetales hele fortjenesten.<br>
5.3 Rabatter på dine produkter regnes af din fortjeneste, som aldrig bliver mindre end 0 kr. Ved kampagner, vi selv kører, deles rabatten mellem os og dig i forhold til prisen.<br>
5.4 Nogle ydelser koster et gebyr, fx at gøre et motiv klar til broderi. Gebyret vises, før du sender designet, og trækkes fra din fortjeneste.<br>
5.5 Du optjener fortjeneste af betalte salg. Refunderes et salg, trækkes fortjenesten tilbage.<br>
5.6 Aftalen omfatter kun fortjeneste af produktsalg. Bonusser, sponsorater og lignende aftales særskilt.</p>

<h3>6. Priser</h3>
<p>6.1 Vores priser kan ses på MitCCMerch.<br>
6.2 Stiger vores priser, giver vi skriftlig besked mindst 14 dage før. Et prisfald kan gælde med det samme.</p>

<h3>7. Udbetaling og afregning</h3>
<p>7.1 Du anmoder selv om udbetaling af din saldo på MitCCMerch på de måder, vi tilbyder der.<br>
7.2 Du accepterer, at vi udsteder afregningsbilaget for dine salg på dine vegne (selvfakturering). Bilaget kan ses på MitCCMerch, når udbetalingen er gennemført. Er der fejl i et bilag, skal du give os besked inden 14 dage.<br>
7.3 Du giver os korrekte oplysninger om dig og din momsregistrering og fortæller os, hvis de ændrer sig.</p>

<h3>8. Samarbejde og kontakt</h3>
<p>8.1 Vi behandler kunder og samarbejdspartnere professionelt og venligt.<br>
8.2 Vi svarer normalt på henvendelser inden for 1–2 hverdage. Du kan skrive til os på [EMAIL].</p>

<h3>9. Varighed og ophør</h3>
<p>9.1 Aftalen gælder fra din accept, indtil den opsiges.<br>
9.2 Begge parter kan opsige aftalen skriftligt til udgangen af en måned. Efter gensidig aftale kan den ophøre med det samme.<br>
9.3 Vi kan ophæve aftalen med det samme, hvis du groft overtræder den, fx ved at uploade designs, der krænker andres rettigheder.<br>
9.4 Ved ophør tages dine produkter ud af webshoppen, og din optjente fortjeneste gøres op og udbetales (slutafregning).</p>

<h3>10. Genforhandling og ændringer</h3>
<p>10.1 Du kan til enhver tid bede om at genforhandle din fortjeneste, dog ikke de første 6 måneder. Undtaget er tilfælde, hvor produktets egenskaber, type eller prisudvikling gør det rimeligt.<br>
10.2 Væsentlige ændringer af aftalen skal du acceptere igen på MitCCMerch. Om mindre ændringer giver vi dig skriftlig besked.</p>

<h3>11. Fortrolighed</h3>
<p>11.1 Din fortjeneste og dine aftalte priser er fortrolige. Ingen af os må videregive dem uden den andens samtykke, heller ikke efter aftalens ophør.</p>

<h3>12. Persondata</h3>
<p>12.1 Vi behandler dine oplysninger efter vores privatlivspolitik.</p>

<h3>13. Lovvalg</h3>
<p>13.1 Aftalen er underlagt dansk ret.</p>
HTML;
	}
}
