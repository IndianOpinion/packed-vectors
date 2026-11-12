# packed-vectors

Stores embeddings in a packed float32 file with a tiny header, rebuilds it atomically, and scans it by cosine in milliseconds without loading rows from the database. Includes the fallback chunked scan and the documented traps of PHP unpack().
