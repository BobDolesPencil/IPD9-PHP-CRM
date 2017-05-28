$( document ).ready(function() {
   $('#addCustomerButton').click(function(event) {
        $('#form1').attr('action', '/addcustomer');      
    }); 
    $("#search").keyup(function () {
        var search = $("#search").val();
        if (search === "")
            search = "all";
        $('#customerList').load('/listcustomers/' + search);
    });
});


