<?php

/*
 * This file initiates a PAdES signature using REST PKI and renders the signature page. The form is posted to
 * another file, pades-signature-action.php, which calls REST PKI again to complete the signature.
 *
 * Both PAdES signature examples, with a server file and with a file uploaded by the user, use this file. The difference
 * is that, when the file is uploaded by the user, the page is called with a URL argument named "userfile".
 */

// The file RestPki.php contains the helper classes to call the REST PKI API
require_once 'RestPki.php';

// The file util.php contains the function getRestPkiClient(), which gives us an instance of the RestPkiClient class
// initialized with the API access token
require_once 'util.php';

use Lacuna\PadesSignatureStarter;
use Lacuna\PadesVisualPositioningPresets;
use Lacuna\StandardSecurityContexts;
use Lacuna\StandardSignaturePolicies;

// This function is called below. It contains examples of signature visual representation positionings. This code is
// only in a separate function in order to organize the various examples, you can pick the one that best suits your
// needs and use it below directly without an encapsulating function.
function getVisualRepresentationPosition($sampleNumber) {

	switch ($sampleNumber) {

		case 1:
			// Example #1: automatic positioning on footnote. This will insert the signature, and future signatures,
			// ordered as a footnote of the last page of the document
			return PadesVisualPositioningPresets::getFootnote(getRestPkiClient());

		case 2:
			// Example #2: get the footnote positioning preset and customize it
			$visualPosition = PadesVisualPositioningPresets::getFootnote(getRestPkiClient());
			$visualPosition->auto->container->left = 2.54;
			$visualPosition->auto->container->bottom = 2.54;
			$visualPosition->auto->container->right = 2.54;
			return $visualPosition;

		case 3:
			// Example #3: automatic positioning on new page. This will insert the signature, and future signatures,
			// in a new page appended to the end of the document.
			return PadesVisualPositioningPresets::getNewPage(getRestPkiClient());

		case 4:
			// Example #4: get the "new page" positioning preset and customize it
			$visualPosition = PadesVisualPositioningPresets::getNewPage(getRestPkiClient());
			$visualPosition->auto->container->left = 2.54;
			$visualPosition->auto->container->top = 2.54;
			$visualPosition->auto->container->right = 2.54;
			$visualPosition->auto->signatureRectangleSize->width = 5;
			$visualPosition->auto->signatureRectangleSize->height = 3;
			return $visualPosition;

		case 5:
			// Example #5: manual positioning
			return [
				'pageNumber' => 0, // zero means the signature will be placed on a new page appended to the end of the document
				'measurementUnits' => 'Centimeters',
				// define a manual position of 5cm x 3cm, positioned at 1 inch from the left and bottom margins
				'manual' => [
					'left' => 2.54,
					'bottom' => 2.54,
					'width' => 5,
					'height' => 3
				]
			];

		case 6:
			// Example #6: custom auto positioning
			return [
				'pageNumber' => -1, // negative values represent pages counted from the end of the document (-1 is last page)
				'measurementUnits' => 'Centimeters',
				'auto' => [
					// Specification of the container where the signatures will be placed, one after the other
					'container' => [
						// Specifying left and right (but no width) results in a variable-width container with the given margins
						'left' => 2.54,
						'right' => 2.54,
						// Specifying bottom and height (but no top) results in a bottom-aligned fixed-height container
						'bottom' => 2.54,
						'height' => 12.31
					],
					// Specification of the size of each signature rectangle
					'signatureRectangleSize' => [
						'width' => 5,
						'height' => 3
					],
					// The signatures will be placed in the container side by side. If there's no room left, the signatures
					// will "wrap" to the next row. The value below specifies the vertical distance between rows
					'rowSpacing' => 1
				]
			];

		default:
			return null;
	}
}

// If the user was redirected here by upload.php (signature with file uploaded by user), the "userfile" URL argument
// will contain the filename under the uploads/ folder. Otherwise (signature with server file), we'll sign a sample
// document.
$pdfPath = isset($_GET['userfile']) ? 'uploads/' . $_GET['userfile'] . '.pdf' : 'content/SampleDocument.pdf';

// Instantiate the PadesSignatureStarter class, responsible for receiving the signature elements and start the signature
// process
$signatureStarter = new PadesSignatureStarter(getRestPkiClient());

// Set the path of PDF to be signed. The file will be read with the standard file_get_contents() function, so the same
// path rules apply.
$signatureStarter->setPdfToSignPath($pdfPath);

// Set the signature policy
$signatureStarter->setSignaturePolicy(StandardSignaturePolicies::PADES_BASIC);

// Set a SecurityContext to be used to determine trust in the certificate chain
$signatureStarter->setSecurityContext(StandardSecurityContexts::PKI_BRAZIL);
// Note: By changing the SecurityContext above you can accept only certificates from a certain PKI, for instance,
// ICP-Brasil (\Lacuna\StandardSecurityContexts::PKI_BRAZIL).

// Set the visual representation for the signature
$signatureStarter->setVisualRepresentation([

	'text' => [

		// The tags {{signerName}} and {{signerNationalId}} will be substituted according to the user's certificate
		// signerName -> full name of the signer
		// signerNationalId -> if the certificate is ICP-Brasil, contains the signer's CPF
		'text' => 'Signed by {{signerName}} ({{signerNationalId}})',

		// Specify that the signing time should also be rendered
		'includeSigningTime' => true

	],

	'image' => [

		// We'll use as background the image content/PdfStamp.png
		'resource' => [
			'content' => base64_encode(file_get_contents('content/PdfStamp.png')),
			'mimeType' => 'image/png'
		],

		// Opacity is an integer from 0 to 100 (0 is completely transparent, 100 is completely opaque).
		'opacity' => 50,

		// Align the image to the right
		'horizontalAlign' => 'Right'

	],

	// Position of the visual representation. We have encapsulated this code in a function to include several
	// possibilities depending on the argument passed to the function. Experiment changing the argument to see
	// different examples of signature positioning. Once you decide which is best for your case, you can place the
	// code directly here.
	'position' => getVisualRepresentationPosition(3)

]);

// Call the startWithWebPki() method, which initiates the signature. This yields the token, a 43-character
// case-sensitive URL-safe string, which identifies this signature process. We'll use this value to call the
// signWithRestPki() method on the Web PKI component (see javascript below) and also to complete the signature after
// the form is submitted (see file pades-signature-action.php). This should not be mistaken with the API access token.
$token = $signatureStarter->startWithWebPki();

// The token acquired above can only be used for a single signature attempt. In order to retry the signature it is
// necessary to get a new token. This can be a problem if the user uses the back button of the browser, since the
// browser might show a cached page that we rendered previously, with a now stale token. To prevent this from happening,
// we call the function setExpiredPage(), located in util.php, which sets HTTP headers to prevent caching of the page.
setExpiredPage();

?><!DOCTYPE html>
<html>
<head>
	<title>PAdES Signature</title>
</head>
<body>

<h2>PAdES Signature</h2>

<?php // notice that we'll post to a different PHP file ?>
<form id="signForm" action="pades-signature-action.php" method="POST">

	<?php // render the $token in a hidden input field ?>
	<input type="hidden" name="token" value="<?= $token ?>">

	<p>
		File to sign:
		You'll are signing <a href='<?= $pdfPath ?>'>this document</a>.
	</p>

	<?php
		// Render a select (combo box) to list the user's certificates. For now it will be empty, we'll populate it
		// later on (see javascript below).
	?>
	<p>
		Choose a certificate:
		<select id="certificateSelect"></select>
	</p>

	<?php
		// Action button. Notice that the "Sign File" button is NOT a submit button. When the user clicks the button,
		// we must first use the Web PKI component to perform the client-side computation necessary and only when
		// that computation is finished we'll submit the form programmatically (see javascript below).
	?>
	<button type="button" onclick="sign()">Sign File</button>
</form>

<?php
// The file below contains the JS lib for accessing the Web PKI component. For more information, see:
// https://webpki.lacunasoftware.com/#/Documentation
?>
<script src="content/js/lacuna-web-pki-2.3.0.js"></script>
<script>

	var pki = new LacunaWebPKI();

	// -------------------------------------------------------------------------------------------------
	// Function called once the page is loaded
	// -------------------------------------------------------------------------------------------------
	function init() {
		// Call the init() method on the LacunaWebPKI object, passing a callback for when
		// the component is ready to be used and another to be called when an error occurs
		// on any of the subsequent operations. For more information, see:
		// https://webpki.lacunasoftware.com/#/Documentation#coding-the-first-lines
		// http://webpki.lacunasoftware.com/Help/classes/LacunaWebPKI.html#method_init
		pki.init({
			ready: loadCertificates, // as soon as the component is ready we'll load the certificates
			defaultError: onWebPkiError
		});
	}

	// -------------------------------------------------------------------------------------------------
	// Function that loads the certificates, either on startup or when the user
	// clicks the "Refresh" button. At this point, the UI is already blocked.
	// -------------------------------------------------------------------------------------------------
	function loadCertificates() {

		// Call the listCertificates() method to list the user's certificates
		pki.listCertificates({

			// specify that expired certificates should be ignored
			filter: pki.filters.isWithinValidity,

			// in order to list only certificates within validity period and having a CPF (ICP-Brasil), use this instead:
			//filter: pki.filters.all(pki.filters.hasPkiBrazilCpf, pki.filters.isWithinValidity),

			// id of the select to be populated with the certificates
			selectId: 'certificateSelect',

			// function that will be called to get the text that should be displayed for each option
			selectOptionFormatter: function (cert) {
				return cert.subjectName + ' (issued by ' + cert.issuerName + ')';
			}

		});
	}

	// -------------------------------------------------------------------------------------------------
	// Function called when the user clicks the "Sign" button
	// -------------------------------------------------------------------------------------------------
	function sign() {

		// Get the thumbprint of the selected certificate
		var select = document.getElementById('certificateSelect');
		if (select.selectedIndex < 0) {
			alert('Please select a certificate');
			return;
		}
		var selectedCertThumbprint = select.options[select.selectedIndex].value;

		// Call signWithRestPki() on the Web PKI component passing the token received from REST PKI and the certificate
		// selected by the user.
		pki.signWithRestPki({
			token: '<?= $token ?>',
			thumbprint: selectedCertThumbprint
		}).success(function() {
			// Once the operation is completed, we submit the form
			document.getElementById('signForm').submit();
		});
	}

	// -------------------------------------------------------------------------------------------------
	// Function called if an error occurs on the Web PKI component
	// -------------------------------------------------------------------------------------------------
	function onWebPkiError(message, error, origin) {
		// Log the error to the browser console (for debugging purposes)
		if (console) {
			console.log('An error has occurred on the signature browser component: ' + message, error);
		}
		// Show the message to the user. You might want to substitute the alert below with a more user-friendly UI
		// component to show the error.
		alert(message);
	}

	// Call the initialization function
	init();

</script>

</body>
</html>