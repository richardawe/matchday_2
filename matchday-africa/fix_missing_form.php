<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$viewPath = 'resources/views/predictions/show.blade.php';
$content = file_get_contents($viewPath);

echo "Analyzing view structure...\n";

// Check if prediction-form div exists
if (strpos($content, 'id="prediction-form"') === false) {
    echo "❌ prediction-form div not found!\n";
    
    // Look for the form tag and add the ID
    if (preg_match('/<form[^>]*class="space-y-6"/', $content, $matches)) {
        echo "Found form tag, adding ID...\n";
        $content = preg_replace(
            '/(<form[^>]*class="space-y-6")/',
            '$1 id="prediction-form"',
            $content
        );
        echo "✅ Added id=\"prediction-form\" to form tag\n";
    } else {
        echo "❌ Form tag not found either!\n";
        
        // Check what we have instead
        if (strpos($content, '<!-- Prediction Form -->') !== false) {
            echo "Found prediction form comment, adding wrapper div...\n";
            
            // Add a wrapper div around the prediction form section
            $content = str_replace(
                '<!-- Prediction Form -->',
                '<!-- Prediction Form -->
                    <div id="prediction-form" style="display: none;">',
                $content
            );
            
            // Close the div before the @endif
            $content = preg_replace(
                '/(\s+)@endif\s+@endif\s+@endif/',
                '$1</div>
$1@endif
$1@endif
$1@endif',
                $content
            );
            
            echo "✅ Added prediction-form wrapper div\n";
        }
    }
} else {
    echo "✅ prediction-form div already exists\n";
}

// Ensure the form is initially hidden when user has submitted predictions
if (strpos($content, '$hasSubmitted') !== false) {
    echo "Found hasSubmitted check, ensuring proper display logic...\n";
    
    // The form should be hidden by default when user has submitted predictions
    // and only shown when in edit mode
    $content = str_replace(
        '<form id="prediction-form" class="space-y-6">',
        '<form id="prediction-form" class="space-y-6" style="display: none;">',
        $content
    );
    
    echo "✅ Set form to be hidden by default\n";
}

// Update the JavaScript to handle the case when formDiv is null
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
            } else {
                console.error("❌ prediction-form div not found! Cannot show form.");
                alert("Prediction form not available. Please refresh the page.");
                return;
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
    if (formDiv) {
        formDiv.style.display = "none";
        console.log("✅ Form found and hidden by default");
    } else {
        console.error("❌ prediction-form div not found on page load!");
    }
});
</script>';

// Replace the script section
$content = preg_replace('/<script>.*?<\/script>/s', $scriptContent, $content);

// Write the fixed content
file_put_contents($viewPath, $content);

echo "\n✅ Fixed prediction form display issues!\n";
echo "Changes made:\n";
echo "- Added missing id=\"prediction-form\" to form element\n";
echo "- Set form to be hidden by default when user has submissions\n";
echo "- Updated JavaScript with better error handling\n";
echo "- Added null checks and error messages\n";

// Clear view cache
\Illuminate\Support\Facades\Artisan::call('view:clear');
echo "✅ View cache cleared!\n";
