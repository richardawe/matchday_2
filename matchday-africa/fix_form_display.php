<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$viewPath = 'resources/views/predictions/show.blade.php';
$content = file_get_contents($viewPath);

echo "Analyzing view structure...\n";

// Check current structure
echo "Looking for prediction form...\n";
if (strpos($content, 'id="prediction-form"') !== false) {
    echo "✅ prediction-form ID found\n";
} else {
    echo "❌ prediction-form ID not found\n";
}

// The issue is that the form is wrapped in @if(!$prediction->isDeadlinePassed())
// We need to always show the form for editing, even after deadline
$content = str_replace(
    '@if(!$prediction->isDeadlinePassed())
                        <form id="prediction-form" class="space-y-6" style="display: none;">',
    '<form id="prediction-form" class="space-y-6" style="display: none;">',
    $content
);

// Remove the corresponding @endif
$content = str_replace(
    '                        </form>
                    @endif',
    '                        </form>',
    $content
);

// Also ensure the edit button is always shown
$content = str_replace(
    '@if(!$prediction->isDeadlinePassed())
            <button id="edit-button" onclick="toggleEditMode()"',
    '<button id="edit-button" onclick="toggleEditMode()"',
    $content
);

// Remove the corresponding @endif for the edit button
$content = str_replace(
    '            </button>
        @endif',
    '            </button>',
    $content
);

// Update the JavaScript with better debugging
$scriptContent = '
<script>
let isEditMode = false;

function toggleEditMode() {
    try {
        isEditMode = !isEditMode;
        
        const submittedDiv = document.getElementById("submitted-predictions");
        const formDiv = document.getElementById("prediction-form");
        const editButton = document.getElementById("edit-button");
        
        console.log("=== TOGGLE EDIT MODE ===");
        console.log("isEditMode:", isEditMode);
        console.log("submittedDiv:", submittedDiv);
        console.log("formDiv:", formDiv);
        console.log("editButton:", editButton);
        
        // Debug: Check all elements with IDs
        console.log("All elements with IDs:");
        const allElements = document.querySelectorAll("[id]");
        allElements.forEach(el => {
            if (el.id.includes("prediction") || el.id.includes("form") || el.id.includes("submit")) {
                console.log("- " + el.id + ":", el);
            }
        });
        
        if (isEditMode) {
            // Show form, hide submitted predictions
            if (submittedDiv) {
                submittedDiv.style.display = "none";
                console.log("✅ Hiding submitted predictions");
            } else {
                console.log("❌ submittedDiv not found");
            }
            
            if (formDiv) {
                formDiv.style.display = "block";
                console.log("✅ Showing prediction form");
            } else {
                console.log("❌ formDiv not found - checking for form elements...");
                const forms = document.querySelectorAll("form");
                console.log("Found " + forms.length + " form elements:", forms);
                
                const predictionForms = document.querySelectorAll("form[id*=\'prediction\']");
                console.log("Found " + predictionForms.length + " prediction forms:", predictionForms);
            }
            
            if (editButton) {
                editButton.textContent = "Cancel Edit";
                editButton.classList.remove("bg-blue-600");
                editButton.classList.add("bg-red-600");
                console.log("✅ Edit button updated to Cancel");
            }
            
            // Pre-fill form with existing predictions
            prefillFormWithExistingPredictions();
        } else {
            // Hide form, show submitted predictions
            if (submittedDiv) {
                submittedDiv.style.display = "block";
                console.log("✅ Showing submitted predictions");
            }
            
            if (formDiv) {
                formDiv.style.display = "none";
                console.log("✅ Hiding prediction form");
            }
            
            if (editButton) {
                editButton.textContent = "Edit Predictions";
                editButton.classList.remove("bg-red-600");
                editButton.classList.add("bg-blue-600");
                console.log("✅ Edit button updated to Edit");
            }
        }
    } catch (error) {
        console.error("❌ Error in toggleEditMode:", error);
        alert("Edit mode error: " + error.message);
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
    console.log("=== PAGE LOADED ===");
    
    const submittedDiv = document.getElementById("submitted-predictions");
    const formDiv = document.getElementById("prediction-form");
    
    console.log("submittedDiv:", submittedDiv);
    console.log("formDiv:", formDiv);
    
    // Debug: List all elements with IDs containing "prediction"
    const predictionElements = document.querySelectorAll("[id*=\'prediction\']");
    console.log("Elements with \'prediction\' in ID:", predictionElements);
    
    // Show submitted predictions by default, hide form
    if (submittedDiv) {
        submittedDiv.style.display = "block";
        console.log("✅ submittedDiv shown");
    } else {
        console.log("❌ submittedDiv not found");
    }
    
    if (formDiv) {
        formDiv.style.display = "none";
        console.log("✅ formDiv hidden");
    } else {
        console.log("❌ formDiv not found");
        
        // Try to find any form element
        const allForms = document.querySelectorAll("form");
        console.log("All forms found:", allForms);
        
        allForms.forEach((form, index) => {
            console.log(`Form ${index}:`, form);
            console.log(`  - ID: ${form.id}`);
            console.log(`  - Classes: ${form.className}`);
            console.log(`  - Style: ${form.style.cssText}`);
        });
    }
});
</script>';

// Replace the script section
$content = preg_replace('/<script>.*?<\/script>/s', $scriptContent, $content);

// Write the fixed content
file_put_contents($viewPath, $content);

echo "\n✅ Fixed prediction form display!\n";
echo "Changes made:\n";
echo "- Removed deadline restriction from form display\n";
echo "- Removed deadline restriction from edit button\n";
echo "- Added comprehensive debugging to JavaScript\n";
echo "- Form should now always be available for editing\n";

// Clear view cache
\Illuminate\Support\Facades\Artisan::call('view:clear');
echo "✅ View cache cleared!\n";
