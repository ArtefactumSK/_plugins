jQuery(document).ready(function ($) {
    // console.log("🔧 Artefactum licence script loaded OK");

    const $licenceInput = $("#license_key");
    if ($licenceInput.length) {
        console.log("Licence input found");

        // Ak je pole prázdne, automaticky vygenerujeme nový kľúč
        if (!$licenceInput.val()) {
            const generatedKey = generateLicenseKey();
            // console.log("✅ Generated licence:", generatedKey);
            $licenceInput.val(generatedKey);
        }

        // Ak chceš, aby sa kľúč dal znova pregenerovať kliknutím na pole:
        $licenceInput.on("dblclick", function () {
            const newKey = generateLicenseKey();
            $(this).val(newKey);
            // console.log("🔁 Regenerated licence:", newKey);
        });
    } else {
        console.warn("Licence input NOT found");
    }

    function generateLicenseKey() {
        const letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        const randPart = () =>
            letters.charAt(Math.floor(Math.random() * 26)) +
            letters.charAt(Math.floor(Math.random() * 26)) +
            Math.floor(10 + Math.random() * 90);
        
        const now = new Date();
        const month = (now.getMonth() + 1).toString().padStart(2, '0'); // Mesiac (1-12) s leading zero
        const year = now.getFullYear().toString().slice(-2); // Posledné 2 číslice roku
        
        return `ART-${randPart()}-${randPart()}-${month}${year}`;
    }
});




// 🔍 Overenie duplicity cez AJAX
$licenceInput.on("blur", function () {
    const currentKey = $(this).val();
    if (!currentKey) return;

    $.post(artefactum_admin.api_url, {
        action: "check_license_duplicate",
        license_key: currentKey,
        _ajax_nonce: artefactum_admin.nonce
    }, function (response) {
        if (response.exists) {
            alert("⚠️ Tento licenčný kľúč už existuje. Zmeňte posledné čísla.");
        }
    });
});
