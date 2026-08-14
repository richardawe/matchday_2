<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$viewPath = 'resources/views/predictions/show.blade.php';
$content = file_get_contents($viewPath);

echo "Fixing prediction form validation issues...\n";

// Fix the score prediction inputs to ensure prediction_value is properly set
$content = str_replace(
    '<!-- Hidden input to store the combined score -->
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" id="score_prediction_{{ $predictionMatch->match->id }}" value="">',
    '<!-- Hidden input to store the combined score -->
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" id="score_prediction_{{ $predictionMatch->match->id }}" value="0-0">',
    $content
);

// Add JavaScript to handle score input changes and update the hidden field
$scoreScript = '
// Handle score input changes
document.addEventListener("DOMContentLoaded", function() {
    // Add event listeners for score inputs
    document.querySelectorAll("input[name^=\'home_score_\'], input[name^=\'away_score_\']").forEach(function(input) {
        input.addEventListener("input", function() {
            const matchId = this.name.split("_")[2];
            updateScorePrediction(matchId);
        });
    });
    
    // Add event listeners for prediction options
    document.querySelectorAll(".prediction-option input[type=\'radio\']").forEach(function(input) {
        input.addEventListener("change", function() {
            const option = this.closest(".prediction-option");
            if (option) {
                // Remove selection from other options in the same group
                const allOptions = document.querySelectorAll("input[name=\'" + this.name + "\']");
                allOptions.forEach(function(otherInput) {
                    const otherOption = otherInput.closest(".prediction-option");
                    if (otherOption) {
                        otherOption.classList.remove("bg-blue-50", "border-blue-500");
                    }
                });
                
                // Add selection to current option
                option.classList.add("bg-blue-50", "border-blue-500");
            }
        });
    });
});

function updateScorePrediction(matchId) {
    const homeScore = document.querySelector("input[name=\'home_score_" + matchId + "\']");
    const awayScore = document.querySelector("input[name=\'away_score_" + matchId + "\']");
    const hiddenInput = document.querySelector("input[name=\'predictions[" + matchId + "][prediction_value]\']");
    const displaySpan = document.querySelector("#score_display_" + matchId);
    
    if (homeScore && awayScore && hiddenInput && displaySpan) {
        const home = homeScore.value || "0";
        const away = awayScore.value || "0";
        const prediction = home + "-" + away;
        
        hiddenInput.value = prediction;
        displaySpan.textContent = prediction;
    }
}

function updateScoreDisplay(matchId) {
    updateScorePrediction(matchId);
}';

// Add the score handling script before the existing script
$content = str_replace(
    '<script>
let isEditMode = false;',
    $scoreScript . '

<script>
let isEditMode = false;',
    $content
);

// Also add form validation before submission
$formValidationScript = '
// Form submission validation
document.addEventListener("DOMContentLoaded", function() {
    const forms = document.querySelectorAll("form[id=\'prediction-form\']");
    forms.forEach(function(form) {
        form.addEventListener("submit", function(e) {
            let hasErrors = false;
            let errorMessages = [];
            
            // Check all prediction inputs
            const predictionInputs = form.querySelectorAll("input[name*=\'[prediction_value]\']");
            predictionInputs.forEach(function(input) {
                if (!input.value || input.value.trim() === "") {
                    const matchId = input.name.match(/\[(\d+)\]/)[1];
                    const matchElement = form.querySelector("[data-match-id=\'" + matchId + "\']");
                    if (matchElement) {
                        const teams = matchElement.querySelector(".text-lg.font-semibold").textContent;
                        errorMessages.push("Please make a prediction for: " + teams);
                        hasErrors = true;
                    }
                }
            });
            
            // Check radio button groups
            const radioGroups = {};
            form.querySelectorAll("input[type=\'radio\'][name*=\'prediction_value\']").forEach(function(input) {
                const groupName = input.name;
                if (!radioGroups[groupName]) {
                    radioGroups[groupName] = false;
                }
                if (input.checked) {
                    radioGroups[groupName] = true;
                }
            });
            
            Object.keys(radioGroups).forEach(function(groupName) {
                if (!radioGroups[groupName]) {
                    const matchId = groupName.match(/\[(\d+)\]/)[1];
                    const matchElement = form.querySelector("[data-match-id=\'" + matchId + "\']");
                    if (matchElement) {
                        const teams = matchElement.querySelector(".text-lg.font-semibold").textContent;
                        errorMessages.push("Please make a prediction for: " + teams);
                        hasErrors = true;
                    }
                }
            });
            
            if (hasErrors) {
                e.preventDefault();
                alert("Please complete all predictions:\\n\\n" + errorMessages.join("\\n"));
                return false;
            }
            
            // Show loading modal
            const loadingModal = document.getElementById("loadingModal");
            if (loadingModal) {
                loadingModal.classList.remove("hidden");
            }
        });
    });
});';

// Add form validation after the score script
$content = str_replace(
    $scoreScript . '

<script>',
    $scoreScript . $formValidationScript . '

<script>',
    $content
);

// Write the fixed content
file_put_contents($viewPath, $content);

echo "Fixed prediction form validation issues!\n";
echo "Changes made:\n";
echo "- Set default value for score prediction hidden inputs\n";
echo "- Added JavaScript to handle score input changes\n";
echo "- Added form validation before submission\n";
echo "- Added event listeners for radio button selection\n";

// Clear view cache
\Illuminate\Support\Facades\Artisan::call('view:clear');
echo "View cache cleared!\n";
