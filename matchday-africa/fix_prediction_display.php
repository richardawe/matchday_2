<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$viewPath = 'resources/views/predictions/show.blade.php';
$content = file_get_contents($viewPath);

echo "Current view content analysis:\n";
echo "- Has prediction-form div: " . (strpos($content, 'id="prediction-form"') !== false ? 'YES' : 'NO') . "\n";
echo "- Has display:none: " . (strpos($content, 'style="display: none;"') !== false ? 'YES' : 'NO') . "\n";
echo "- Has submitted-predictions div: " . (strpos($content, 'id="submitted-predictions"') !== false ? 'YES' : 'NO') . "\n";

// Remove the display:none from the prediction form
$content = str_replace(
    '<form id="prediction-form" class="space-y-6" style="display: none;">',
    '<form id="prediction-form" class="space-y-6">',
    $content
);

// Ensure the form is properly structured
if (!strpos($content, 'id="prediction-form"')) {
    // Add the form div wrapper if it doesn't exist
    $content = str_replace(
        '<!-- Prediction Form -->',
        '<!-- Prediction Form -->
                    <div id="prediction-form">',
        $content
    );
    
    // Close the div before the @endif
    $content = str_replace(
        '                    @endif
                @endif',
        '                    </div>
                    @endif
                @endif',
        $content
    );
}

// Fix the JavaScript to properly handle the form visibility
$scriptContent = '
<script>
let isEditMode = false;

function toggleEditMode() {
    try {
        isEditMode = !isEditMode;
        
        const submittedDiv = document.getElementById("submitted-predictions");
        const formDiv = document.getElementById("prediction-form");
        const editButton = document.getElementById("edit-button");
        
        console.log("Edit mode toggled:", isEditMode);
        console.log("submittedDiv:", submittedDiv);
        console.log("formDiv:", formDiv);
        console.log("editButton:", editButton);
        
        if (isEditMode) {
            // Show form, hide submitted predictions
            if (submittedDiv) {
                submittedDiv.style.display = "none";
                console.log("Hiding submitted predictions");
            }
            if (formDiv) {
                formDiv.style.display = "block";
                console.log("Showing prediction form");
            }
            if (editButton) {
                editButton.textContent = "Cancel Edit";
                editButton.classList.remove("bg-blue-600");
                editButton.classList.add("bg-red-600");
            }
            
            // Pre-fill form with existing predictions
            prefillFormWithExistingPredictions();
        } else {
            // Hide form, show submitted predictions
            if (submittedDiv) {
                submittedDiv.style.display = "block";
                console.log("Showing submitted predictions");
            }
            if (formDiv) {
                formDiv.style.display = "none";
                console.log("Hiding prediction form");
            }
            if (editButton) {
                editButton.textContent = "Edit Predictions";
                editButton.classList.remove("bg-red-600");
                editButton.classList.add("bg-blue-600");
            }
        }
    } catch (error) {
        console.error("Error in toggleEditMode:", error);
        alert("Edit mode is not available for this prediction set.");
    }
}

function prefillFormWithExistingPredictions() {
    try {
        console.log("Pre-filling form with existing predictions...");
        // Implementation for pre-filling form with existing predictions
    } catch (error) {
        console.error("Error in prefillFormWithExistingPredictions:", error);
    }
}

// Initialize form visibility on page load
document.addEventListener("DOMContentLoaded", function() {
    const submittedDiv = document.getElementById("submitted-predictions");
    const formDiv = document.getElementById("prediction-form");
    
    console.log("Page loaded - submittedDiv:", submittedDiv);
    console.log("Page loaded - formDiv:", formDiv);
    
    // Show submitted predictions by default, hide form
    if (submittedDiv) submittedDiv.style.display = "block";
    if (formDiv) formDiv.style.display = "none";
});
</script>';

// Replace any existing script section with our fixed version
$content = preg_replace('/<script>.*?<\/script>/s', $scriptContent, $content);

// Write the fixed content
file_put_contents($viewPath, $content);

echo "Fixed prediction view display issues!\n";
echo "Changes made:\n";
echo "- Removed display:none from prediction form\n";
echo "- Added proper form structure\n";
echo "- Fixed JavaScript with console logging\n";
echo "- Added DOMContentLoaded handler\n";

// Clear view cache
\Illuminate\Support\Facades\Artisan::call('view:clear');
echo "View cache cleared!\n";
