<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$viewPath = 'resources/views/predictions/show.blade.php';
$content = file_get_contents($viewPath);

echo "Fixing Blade template syntax errors...\n";

// Check for mismatched @endif tags
$ifCount = substr_count($content, '@if');
$endifCount = substr_count($content, '@endif');
$elseifCount = substr_count($content, '@elseif');
$elseCount = substr_count($content, '@else');

echo "Blade directive counts:\n";
echo "- @if: $ifCount\n";
echo "- @endif: $endifCount\n";
echo "- @elseif: $elseifCount\n";
echo "- @else: $elseCount\n";

// The issue is likely that we removed some @if conditions but left the corresponding @endif
// Let's restore the proper structure

// First, let's restore the original structure for the form
$content = str_replace(
    '<form id="prediction-form" class="space-y-6" style="display: none;">',
    '@if(!$prediction->isDeadlinePassed())
                        <form id="prediction-form" class="space-y-6" style="display: none;">',
    $content
);

$content = str_replace(
    '                        </form>',
    '                        </form>
                    @endif',
    $content
);

// Restore the edit button condition
$content = str_replace(
    '<button id="edit-button" onclick="toggleEditMode()"',
    '@if(!$prediction->isDeadlinePassed())
            <button id="edit-button" onclick="toggleEditMode()"',
    $content
);

$content = str_replace(
    '            </button>',
    '            </button>
        @endif',
    $content
);

// Fix the JavaScript - remove the broken parts
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
        console.log("Pre-filling form with existing predictions...");
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

// Replace the script section
$content = preg_replace('/<script>.*?<\/script>/s', $scriptContent, $content);

// Write the fixed content
file_put_contents($viewPath, $content);

echo "Fixed Blade template syntax!\n";

// Clear view cache
\Illuminate\Support\Facades\Artisan::call('view:clear');
echo "View cache cleared!\n";
