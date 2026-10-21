<?php
/**
 * Draft texts of the YouTube chatbot's privacy policy and terms.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Legal;

defined( 'ABSPATH' ) || exit;

/**
 * The drafts an administrator starts from (ADR-071): complete texts in
 * English and Danish with placeholders ([COMPANY], [CVR], [ADDRESS],
 * [EMAIL]). They describe what the code does (ADR-070): the scopes, the data
 * read and stored, retention, revocation and the statements Google requires
 * (YouTube Terms of Service, Google Privacy Policy, the Limited Use
 * requirements of the Google API Services User Data Policy). They are a
 * starting point, not legal advice.
 */
final class YouTubeLegalDefaults {

	/**
	 * The draft of a document in a language ('' for unknown ones).
	 *
	 * @param string $document 'privacy' or 'terms'.
	 * @param string $language 'en' or 'da'.
	 */
	public static function text( string $document, string $language ): string {
		return match ( $document . '_' . $language ) {
			'privacy_en' => self::PRIVACY_EN,
			'privacy_da' => self::PRIVACY_DA,
			'terms_en'   => self::TERMS_EN,
			'terms_da'   => self::TERMS_DA,
			default      => '',
		};
	}

	private const PRIVACY_EN = <<<'HTML'
<h2>Who we are</h2>
<p>This privacy policy explains how [COMPANY] (CVR [CVR], [ADDRESS]; "we") processes data when the YouTube chatbot of our creator dashboard is used. The YouTube chatbot writes a message in a creator's YouTube live chat when someone buys the creator's merchandise. We are the data controller. Contact: [EMAIL].</p>

<h2>Who this policy covers</h2>
<ul>
<li>Creators who connect their YouTube channel to the chatbot.</li>
<li>Administrators who log in the chatbot's own YouTube account (the bot account).</li>
<li>Buyers whose voluntary username is shown in a message.</li>
</ul>

<h2>Data we access through the YouTube API Services</h2>
<ul>
<li><strong>Creators</strong> give read-only access (scope <code>youtube.readonly</code>). We read the channel's ID and name, to show which channel is connected, and whether the channel is live right now, including the livestream's video ID and live chat ID, to know where the message goes. We do not read videos, comments, subscribers, chat messages, analytics or any other data.</li>
<li><strong>The bot account</strong> is our own YouTube account. It gives access to manage YouTube (scope <code>youtube.force-ssl</code>), which we use only to read the bot's channel ID and name and to write messages in the live chats of connected creators.</li>
</ul>

<h2>Data we store</h2>
<ul>
<li>The ID and name of the connected channel and when it was connected.</li>
<li>Google's access and refresh tokens, encrypted on our server. They are only sent to Google, are never shown to anyone and are not logged.</li>
<li>For a short time (at most 10 minutes) whether the creator is live and the live chat ID.</li>
<li>A log of the messages per order line: the order, the outcome (sent, waiting, skipped or failed), the reason and the time. Not the text of the message.</li>
<li>When Google refuses a message: a technical log entry with Google's error text, without tokens.</li>
<li>A record of connecting and removing the bot and of accepting this policy and the terms (channel name, version and time) in our audit log.</li>
</ul>

<h2>The messages</h2>
<p>A message contains what the creator's template sets, typically the buyer's voluntary username from the checkout and the product bought. Buyers choose themselves whether to give a username. The message is published in a public YouTube live chat and is processed by YouTube under the Google Privacy Policy.</p>

<h2>Purpose and legal basis</h2>
<p>We process the data to announce purchases in the creator's live chat, as agreed with the creator (GDPR art. 6(1)(b)), and to run the service securely, with logs and audit records (GDPR art. 6(1)(f), our legitimate interest). A buyer's username is shown because the buyer chose to give it for this purpose.</p>

<h2>Sharing</h2>
<p>We share data only with Google (YouTube) as needed to find the livestream and write the message, and with our hosting provider, who processes data on our behalf. We do not sell data, use it for advertising, share it with anyone else or use it to train AI models.</p>

<h2>Google API Services User Data Policy</h2>
<p>[COMPANY]'s use and transfer to any other app of information received from Google APIs will adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Services User Data Policy</a>, including the Limited Use requirements.</p>
<p>The YouTube chatbot uses YouTube API Services. By using it you also agree to the <a href="https://www.youtube.com/t/terms">YouTube Terms of Service</a>. Google's processing of data is described in the <a href="https://policies.google.com/privacy">Google Privacy Policy</a>.</p>

<h2>Retention and deletion</h2>
<ul>
<li>Tokens and channel data are kept until the bot is removed from the channel. They are then deleted, and the tokens are revoked at Google. If Google tells us that the access was revoked, we delete them automatically.</li>
<li>Whether a creator is live is kept for at most 10 minutes.</li>
<li>The message log, technical log entries and audit records are kept as long as needed for documentation and security.</li>
</ul>

<h2>How to revoke access</h2>
<p>A creator can remove the bot at any time in the creator dashboard (Alerts → Chatbot → "Remove the bot"). This deletes the tokens and revokes the access at Google. You can also revoke the access in your <a href="https://myaccount.google.com/permissions">Google account's security settings</a>; we then delete the tokens the next time the chatbot needs them.</p>

<h2>Your rights</h2>
<p>You have the right to access, rectification, erasure, restriction of processing, data portability and to object. Contact us at [EMAIL]. You can complain to the Danish Data Protection Agency (<a href="https://www.datatilsynet.dk">Datatilsynet</a>).</p>

<h2>Cookies</h2>
<p>These pages set no cookies. The creator dashboard uses the necessary login cookies of WordPress.</p>

<h2>Changes</h2>
<p>When we change this policy in a way that matters, we publish a new version. You must accept it before the chatbot writes in your chat again. The version and its date are shown at the top of the page.</p>
HTML;

	private const PRIVACY_DA = <<<'HTML'
<h2>Hvem vi er</h2>
<p>Denne privatlivspolitik forklarer, hvordan [COMPANY] (CVR [CVR], [ADDRESS]; "vi") behandler data, når YouTube-chatbotten i vores creator-dashboard bruges. YouTube-chatbotten skriver en besked i en creators YouTube-livechat, når nogen køber creatorens merchandise. Vi er dataansvarlige. Kontakt: [EMAIL].</p>

<h2>Hvem politikken gælder for</h2>
<ul>
<li>Creators, der forbinder deres YouTube-kanal med chatbotten.</li>
<li>Administratorer, der logger chatbottens egen YouTube-konto (bot-kontoen) ind.</li>
<li>Købere, hvis frivillige brugernavn vises i en besked.</li>
</ul>

<h2>Data vi tilgår via YouTube API Services</h2>
<ul>
<li><strong>Creators</strong> giver skrivebeskyttet adgang (scope <code>youtube.readonly</code>). Vi læser kanalens id og navn, så det kan vises, hvilken kanal der er forbundet, og om kanalen er live lige nu, inklusive livestreamens video-id og livechat-id, så vi ved, hvor beskeden skal skrives. Vi læser ikke videoer, kommentarer, abonnenter, chatbeskeder, statistik eller andre data.</li>
<li><strong>Bot-kontoen</strong> er vores egen YouTube-konto. Den giver adgang til at administrere YouTube (scope <code>youtube.force-ssl</code>), som vi kun bruger til at læse bottens kanal-id og navn og til at skrive beskeder i forbundne creators' livechats.</li>
</ul>

<h2>Data vi gemmer</h2>
<ul>
<li>Den forbundne kanals id og navn, og hvornår den blev forbundet.</li>
<li>Googles access- og refresh-tokens, krypteret på vores server. De sendes kun til Google, vises aldrig for nogen og logges ikke.</li>
<li>I kort tid (højst 10 minutter), om creatoren er live, og livechat-id'et.</li>
<li>En log over beskederne pr. ordrelinje: ordren, resultatet (sendt, venter, sprunget over eller fejlet), grunden og tidspunktet. Ikke beskedens tekst.</li>
<li>Når Google afviser en besked: en teknisk logpost med Googles fejltekst, uden tokens.</li>
<li>En registrering af, at botten forbindes og fjernes, og at denne politik og vilkårene godkendes (kanalnavn, version og tidspunkt), i vores audit-log.</li>
</ul>

<h2>Beskederne</h2>
<p>En besked indeholder det, creatorens skabelon angiver, typisk købers frivillige brugernavn fra checkout og det købte produkt. Køberen vælger selv, om der gives et brugernavn. Beskeden offentliggøres i en offentlig YouTube-livechat og behandles af YouTube efter Googles privatlivspolitik.</p>

<h2>Formål og retsgrundlag</h2>
<p>Vi behandler data for at annoncere køb i creatorens livechat som aftalt med creatoren (databeskyttelsesforordningen art. 6, stk. 1, litra b) og for at drive tjenesten sikkert med logs og audit (art. 6, stk. 1, litra f, vores legitime interesse). En købers brugernavn vises, fordi køberen selv har valgt at give det til dette formål.</p>

<h2>Videregivelse</h2>
<p>Vi deler kun data med Google (YouTube), i det omfang det er nødvendigt for at finde livestreamen og skrive beskeden, og med vores hostingudbyder, der behandler data på vores vegne. Vi sælger ikke data, bruger dem ikke til annoncering, deler dem ikke med andre og bruger dem ikke til at træne AI-modeller.</p>

<h2>Google API Services User Data Policy</h2>
<p>[COMPANY]'s brug og overførsel til andre apps af oplysninger modtaget fra Google API'er overholder <a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Services User Data Policy</a>, herunder kravene om begrænset brug (Limited Use).</p>
<p>YouTube-chatbotten bruger YouTube API Services. Ved at bruge den accepterer du også <a href="https://www.youtube.com/t/terms">YouTubes servicevilkår</a>. Googles behandling af data er beskrevet i <a href="https://policies.google.com/privacy">Googles privatlivspolitik</a>.</p>

<h2>Opbevaring og sletning</h2>
<ul>
<li>Tokens og kanaldata gemmes, indtil botten fjernes fra kanalen. Derefter slettes de, og tokens tilbagekaldes hos Google. Hvis Google oplyser, at adgangen er tilbagekaldt, sletter vi dem automatisk.</li>
<li>Om en creator er live, gemmes i højst 10 minutter.</li>
<li>Beskedloggen, tekniske logposter og audit-registreringer gemmes, så længe det er nødvendigt for dokumentation og sikkerhed.</li>
</ul>

<h2>Sådan tilbagekalder du adgangen</h2>
<p>En creator kan til enhver tid fjerne botten i creator-dashboardet (Alerts → Chatbot → "Fjern botten"). Det sletter tokens og tilbagekalder adgangen hos Google. Du kan også tilbagekalde adgangen i din <a href="https://myaccount.google.com/permissions">Google-kontos sikkerhedsindstillinger</a>; vi sletter så tokens, næste gang chatbotten skal bruge dem.</p>

<h2>Dine rettigheder</h2>
<p>Du har ret til indsigt, berigtigelse, sletning, begrænsning af behandling, dataportabilitet og til at gøre indsigelse. Kontakt os på [EMAIL]. Du kan klage til <a href="https://www.datatilsynet.dk">Datatilsynet</a>.</p>

<h2>Cookies</h2>
<p>Disse sider sætter ingen cookies. Creator-dashboardet bruger WordPress' nødvendige login-cookies.</p>

<h2>Ændringer</h2>
<p>Når vi ændrer politikken på en måde, der har betydning, offentliggør vi en ny version. Du skal godkende den, før chatbotten skriver i din chat igen. Versionen og dens dato står øverst på siden.</p>
HTML;

	private const TERMS_EN = <<<'HTML'
<h2>1. The service</h2>
<p>The YouTube chatbot is part of the creator dashboard of [COMPANY] (CVR [CVR], [ADDRESS]; "we"). When someone buys a creator's merchandise, the chatbot writes a message in the creator's YouTube live chat from our bot account.</p>

<h2>2. Acceptance</h2>
<p>By ticking the box and connecting your YouTube channel (or, for administrators, logging in the bot account) you accept these terms and the privacy policy of the YouTube chatbot. If you do not accept them, do not use the YouTube chatbot.</p>

<h2>3. Requirements</h2>
<ul>
<li>You are a creator with access to the creator dashboard and own the YouTube channel you connect, or are allowed to connect it.</li>
<li>You follow the <a href="https://www.youtube.com/t/terms">YouTube Terms of Service</a> and YouTube's community guidelines. The chatbot uses YouTube API Services.</li>
</ul>

<h2>4. What the chatbot does</h2>
<ul>
<li>It reads only whether your channel is live and where its live chat is (read-only access).</li>
<li>It writes only messages about purchases of your merchandise in the format of your template, test messages you send and messages an administrator sends as the bot.</li>
<li>It does not read, moderate or store your chat.</li>
</ul>

<h2>5. Your responsibility</h2>
<ul>
<li>You are responsible for the text of your template; it must not be unlawful or break YouTube's rules.</li>
<li>Your chat settings decide whether the bot may write: in a private livestream, or a chat for subscribers or members only, YouTube refuses the bot. You can make the bot a moderator of your channel.</li>
</ul>

<h2>6. Availability</h2>
<p>The chatbot depends on YouTube and on YouTube's daily quota. It writes only during a livestream: if you are not live when someone buys, it checks once more after 5 minutes and otherwise skips the message. We do not guarantee that every purchase is announced, and we may pause the chatbot.</p>

<h2>7. Ending</h2>
<p>You can remove the bot from your channel at any time in the creator dashboard; the access is then revoked at Google. We may disconnect a channel on misuse or when the service ends.</p>

<h2>8. Changes</h2>
<p>When we change these terms in a way that matters, we publish a new version. The chatbot does not write in your chat until you have accepted it.</p>

<h2>9. Liability</h2>
<p>The chatbot is provided as it is. To the extent the law allows, we are not liable for indirect loss, messages that are not written, or YouTube's decisions about your channel.</p>

<h2>10. Law and contact</h2>
<p>Danish law applies, and disputes are settled by the Danish courts. Contact: [EMAIL].</p>
HTML;

	private const TERMS_DA = <<<'HTML'
<h2>1. Tjenesten</h2>
<p>YouTube-chatbotten er en del af creator-dashboardet hos [COMPANY] (CVR [CVR], [ADDRESS]; "vi"). Når nogen køber en creators merchandise, skriver chatbotten en besked i creatorens YouTube-livechat fra vores bot-konto.</p>

<h2>2. Godkendelse</h2>
<p>Når du sætter kryds og forbinder din YouTube-kanal (eller, for administratorer, logger bot-kontoen ind), accepterer du disse vilkår og YouTube-chatbottens privatlivspolitik. Hvis du ikke accepterer dem, må du ikke bruge YouTube-chatbotten.</p>

<h2>3. Krav</h2>
<ul>
<li>Du er creator med adgang til creator-dashboardet og ejer den YouTube-kanal, du forbinder, eller har lov til at forbinde den.</li>
<li>Du overholder <a href="https://www.youtube.com/t/terms">YouTubes servicevilkår</a> og YouTubes retningslinjer for fællesskabet. Chatbotten bruger YouTube API Services.</li>
</ul>

<h2>4. Hvad chatbotten gør</h2>
<ul>
<li>Den læser kun, om din kanal er live, og hvor livechatten er (skrivebeskyttet adgang).</li>
<li>Den skriver kun beskeder om køb af din merchandise i din skabelons format, testbeskeder, du sender, og beskeder, en administrator sender som botten.</li>
<li>Den læser, modererer og gemmer ikke din chat.</li>
</ul>

<h2>5. Dit ansvar</h2>
<ul>
<li>Du er ansvarlig for teksten i din skabelon; den må ikke være ulovlig eller bryde YouTubes regler.</li>
<li>Dine chatindstillinger afgør, om botten må skrive: i en privat livestream eller en chat kun for abonnenter eller medlemmer afviser YouTube botten. Du kan gøre botten til moderator på din kanal.</li>
</ul>

<h2>6. Tilgængelighed</h2>
<p>Chatbotten afhænger af YouTube og af YouTubes daglige kvote. Den skriver kun under en livestream: er du ikke live, når nogen køber, tjekker den én gang til efter 5 minutter og springer ellers beskeden over. Vi garanterer ikke, at alle køb bliver annonceret, og vi kan sætte chatbotten på pause.</p>

<h2>7. Ophør</h2>
<p>Du kan til enhver tid fjerne botten fra din kanal i creator-dashboardet; adgangen tilbagekaldes så hos Google. Vi kan afbryde en kanal ved misbrug, eller når tjenesten ophører.</p>

<h2>8. Ændringer</h2>
<p>Når vi ændrer vilkårene på en måde, der har betydning, offentliggør vi en ny version. Chatbotten skriver ikke i din chat, før du har godkendt den.</p>

<h2>9. Ansvar</h2>
<p>Chatbotten leveres, som den er. I det omfang loven tillader det, er vi ikke ansvarlige for indirekte tab, beskeder, der ikke bliver skrevet, eller YouTubes beslutninger om din kanal.</p>

<h2>10. Lovvalg og kontakt</h2>
<p>Dansk ret gælder, og tvister afgøres ved de danske domstole. Kontakt: [EMAIL].</p>
HTML;
}
