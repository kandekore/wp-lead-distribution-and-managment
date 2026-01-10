
document.addEventListener('DOMContentLoaded', function() {
    const addButton = document.getElementById('add-postcode-area'); // Assume you have this button in your form
    const container = document.getElementById('postcode-areas-container'); // A container where your postcode inputs reside

    addButton.addEventListener('click', function(e) {
        e.preventDefault();

        // Create a new input element
        const newInput = document.createElement('input');
        newInput.setAttribute('type', 'text');
        newInput.setAttribute('name', 'postcode_areas[]'); // Ensure the name matches what's expected by your PHP processing

        // Append this new input to your container
        container.appendChild(newInput);
    });
});

