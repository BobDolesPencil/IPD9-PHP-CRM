function validateForm() {
    var x = document.forms["form"]["firstname"].value;
    if ((x.length < 2) || (x.length > 50)) {
        window.alert("First name must be 2 to 50 characters!");
        document.getElementById("firstname").focus();
        return false;
    }
    x = document.forms["form"]["lastname"].value;
    if ((x.length < 2) || (x.length > 50)) {
        window.alert("Last name must be 2 to 50 characters!");
        document.getElementById("lastname").focus();
        return false;
    }
    x = new Date(document.getElementById("birthdate").value);
    var d = new Date();
    if (x.getFullYear() >= (d.getFullYear() - 25)) {
        window.alert("Hire a person younger than 25 years old!!!! It is not possible.");
        document.getElementById("birthdate").focus();
        return false;
    }
    var phoneno = /^\(?([0-9]{3})\)?[-. ]?([0-9]{3})[-. ]?([0-9]{4})$/;
    x = document.forms["form"]["phone"].value;
    if (!x.match(phoneno)) {
        window.alert("Please Enter a valid phone number!");
        document.getElementById("phone").focus();
        return false;
    }
    x = new Date(document.getElementById("hiredate").value);
    var d = new Date();
    if (x > d) {
        window.alert("Enter a valid Hire Date!");
        document.getElementById("hiredate").focus();
        return false;
    }
    x = document.getElementById("username").value;
    var username = /^([a-zA-Z0-9]{6,15})$/;
    if (!x.match(username)) {
        window.alert("Username must between 6-15 charachters. Mixed up alphabet and numbers only!");
        document.getElementById("username").focus();
        return false;
    }
    x = document.getElementById("postalcode").value;
    var postalcode = /^[abceghjklmnprstvxyABCEGHJKLMNPRSTVXY][0-9][abceghjklmnprstvwxyzABCEGHJKLMNPRSTVWXYZ] ?[0-9][abceghjklmnprstvwxyzABCEGHJKLMNPRSTVWXYZ][0-9]$/;
    if (!x.match(postalcode)) {
        window.alert("Please Enter a valid Postal Code!");
        document.getElementById("postalcode").focus();
        return false;
    }
    var validFile = document.getElementById("imageupload").files.length;
    if(validFile === 0){
        window.alert("Image must be uploaded. You can not leave it empty!");
        return false;
    }
    var pass1 = document.getElementById("password").value;
    var password = /^(?=.*[0-9])(?=.*[!@#$%^&*])[a-zA-Z0-9!@#$%^&*]{6,16}$/;
    if (!pass1.match(password)) {
        window.alert("Valid Password has at least one number and one special character!");
        document.getElementById("password").focus();
        return false;
    }
    var pass2 = document.getElementById("password2").value;
    if (pass2 !== pass1) {
        window.alert("Both Passwords must be match!");
        document.getElementById("password2").focus();
        return false;
    }
    return true;
}
function readURL(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();

        reader.onload = function (e) {
            $('#img').attr('src', e.target.result);
        };
        reader.readAsDataURL(input.files[0]);
    }
}