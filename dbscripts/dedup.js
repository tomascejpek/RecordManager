use recman;

db.record.find().forEach(function(doc) {
    var source_id = doc.source_id;
    var isbns  = [];
    var titles = [];
    var ids    = [];
    if (doc.isbn_keys !== undefined) {
        isbns = doc.isbn_keys;
    }
    if (doc.title_keys !== undefined) {
        titles = doc.title_keys;
    }
    if (doc.id_keys !== undefined) {
        ids = doc.id_keys;
    }
    var duplicates = db.record.find({
        source_id : { $ne : source_id },
        $or :[
            { isbn_keys  : { $in : isbns  } },
            { title_keys : { $in : titles } },
            { id_keys    : { $in : ids    } }
        ]
    });
    if (duplicates.hasNext()) {
        print("duplicate matched: ", duplicates[0]._id, doc._id);
    }    
});