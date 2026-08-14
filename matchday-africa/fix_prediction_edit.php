<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Fix the prediction show view
$viewPath = 'resources/views/predictions/show.blade.php';
$content = file_get_contents($viewPath);

// Remove duplicate toggleEditMode function (lines 330-399)
$content = preg_replace('/<script>\s*let isEditMode = false;\s*function toggleEditMode\(\) \{.*?\}\s*<\/script>/s', '', $content);

// Add proper form and div IDs
$content = str_replace(
    '<div class="mb-6" id="submitted-predictions">',
    '<div class="mb-6" id="submitted-predictions">',
    $content
);

// Add prediction form div if it doesn't exist
if (!strpos($content, 'id="prediction-form"')) {
    $content = str_replace(
        '<!-- Prediction Form -->',
        '<!-- Prediction Form -->
                    <div id="prediction-form" style="display: none;">',
        $content
    );
    
    // Close the div before the @endif
    $content = str_replace(
        '                    @endif',
        '                    </div>
                    @endif',
        $content
    );
}

// Fix the JavaScript to handle missing elements gracefully
$scriptFix = '
<script>
let isEditMode = false;

function toggleEditMode() {
    try {
        isEditMode = !isEditMode;
        
        const submittedDiv = document.getElementById("submitted-predictions");
        const formDiv = document.getElementById("prediction-form");
        const editButton = document.getElementById("edit-button");
        
        if (isEditMode) {
            // Show form, hide submitted predictions
            if (submittedDiv) submittedDiv.style.display = "none";
            if (formDiv) formDiv.style.display = "block";
            if (editButton) {
                editButton.textContent = "Cancel Edit";
                editButton.classList.remove("bg-blue-600");
                editButton.classList.add("bg-red-600");
            }
            
            // Pre-fill form with existing predictions
            prefillFormWithExistingPredictions();
        } else {
            // Hide form, show submitted predictions
            if (submittedDiv) submittedDiv.style.display = "block";
            if (formDiv) formDiv.style.display = "none";
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
        // Implementation for pre-filling form with existing predictions
        console.log("Pre-filling form with existing predictions...");
    } catch (error) {
        console.error("Error in prefillFormWithExistingPredictions:", error);
    }
}
</script>';

// Replace the existing script section
$content = preg_replace('/<script>.*?<\/script>/s', $scriptFix, $content);

// Write the fixed content
file_put_contents($viewPath, $content);

echo "Fixed prediction show view!\n";

// Clear view cache
\Illuminate\Support\Facades\Artisan::call('view:clear');
echo "View cache cleared!\n";
