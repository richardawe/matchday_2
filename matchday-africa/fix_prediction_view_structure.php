<?php
// Read the current view file
$viewContent = file_get_contents('resources/views/predictions/show.blade.php');

// Find the form section that's inside @if(!$hasSubmitted)
$formStartPattern = '/@if\(\!\$hasSubmitted\)\s*<!-- Prediction Form -->\s*<form[^>]*>/';
$formEndPattern = '/<\/form>\s*@endif/';

// Extract the form content
if (preg_match($formStartPattern . '(.*?)' . $formEndPattern, $viewContent, $matches, PREG_OFFSET_CAPTURE)) {
    $formContent = $matches[1][0];
    
    // Remove the form from the @if(!$hasSubmitted) section
    $viewContent = preg_replace($formStartPattern . '(.*?)' . $formEndPattern, '', $viewContent, 1);
    
    // Add the form after the @if($hasSubmitted) section ends
    $insertPoint = strpos($viewContent, '@endif') + 7; // After the first @endif
    
    // Insert the form with proper styling
    $formWithId = str_replace('<form', '<form id="prediction-form" style="display: ' . ($hasSubmitted ? 'none' : 'block') . ';"', $formContent);
    
    $viewContent = substr_replace($viewContent, "\n" . $formWithId . "\n", $insertPoint, 0);
    
    // Add the JavaScript for edit mode
    $jsCode = '
<script>
let isEditMode = false;

function toggleEditMode() {
    isEditMode = !isEditMode;
    
    if (isEditMode) {
        // Show form, hide submitted predictions
        document.getElementById("submitted-predictions").style.display = "none";
        document.getElementById("prediction-form").style.display = "block";
        document.getElementById("edit-button").textContent = "Cancel Edit";
        document.getElementById("edit-button").classList.remove("bg-blue-600");
        document.getElementById("edit-button").classList.add("bg-red-600");
        
        // Pre-fill form with existing predictions
        prefillFormWithExistingPredictions();
    } else {
        // Hide form, show submitted predictions
        document.getElementById("submitted-predictions").style.display = "block";
        document.getElementById("prediction-form").style.display = "none";
        document.getElementById("edit-button").textContent = "Edit Predictions";
        document.getElementById("edit-button").classList.remove("bg-red-600");
        document.getElementById("edit-button").classList.add("bg-blue-600");
    }
}

function prefillFormWithExistingPredictions() {
    // This function will be called to pre-fill the form with existing predictions
    // The existing predictions are available in the page as userPredictions
    @if($hasSubmitted)
        @foreach($userPredictions as $userPrediction)
            @if($userPrediction->prediction_type === "score")
                const homeScore_{{ $userPrediction->match->id }} = document.querySelector("input[name=\'home_score_{{ $userPrediction->match->id }}\']");
                const awayScore_{{ $userPrediction->match->id }} = document.querySelector("input[name=\'away_score_{{ $userPrediction->match->id }}\']");
                if (homeScore_{{ $userPrediction->match->id }} && awayScore_{{ $userPrediction->match->id }}) {
                    const scores = "{{ $userPrediction->prediction_value }}".split("-");
                    homeScore_{{ $userPrediction->match->id }}.value = scores[0] || "0";
                    awayScore_{{ $userPrediction->match->id }}.value = scores[1] || "0";
                    updateScoreDisplay({{ $userPrediction->match->id }});
                }
            @else
                const radio_{{ $userPrediction->match->id }} = document.querySelector("input[name=\'prediction_{{ $userPrediction->match->id }}\'][value=\'{{ $userPrediction->prediction_value }}\']");
                if (radio_{{ $userPrediction->match->id }}) {
                    radio_{{ $userPrediction->match->id }}.checked = true;
                    radio_{{ $userPrediction->match->id }}.closest(".prediction-option").classList.add("bg-blue-50", "border-blue-500");
                }
            @endif
        @endforeach
    @endif
}
</script>';

    // Insert JavaScript before @endsection
    $viewContent = str_replace('@endsection', $jsCode . "\n@endsection", $viewContent);
    
    // Write the updated content
    file_put_contents('resources/views/predictions/show.blade.php', $viewContent);
    
    echo "View structure updated successfully!\n";
    echo "The form is now available in both states and can be toggled.\n";
} else {
    echo "Could not find the form structure to modify.\n";
}
