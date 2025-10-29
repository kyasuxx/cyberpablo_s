
import re
import os
formal_patterns = [
    (r"\bwon't\b", "will not"),
    (r"\bcan't\b", "cannot"),
    (r"\blet's\b", "let us"),
    (r"\bi'm\b", "I am"),
    (r"\bain't\b", "am not"),
    (r"\b(\w+)'ll\b", r"\1 will"),
    (r"\b(\w+)n't\b", r"\1 not"),
    (r"\b(\w+)'ve\b", r"\1 have"),
    #smarter version to check for possessive (Teyvat's becomes Teyvat is in formal checking)
    (r"\b(\w+)'s(?!\s+\w)", r"\1 is"),
    #special cases for common pronouns
    (r"\b(He|She|It|That|There|Who|What|When|Where|How|Here|Let)'s\b", r"\1 is"),
    (r"\b(\w+)'re\b", r"\1 are"),
    (r"\b(\w+)'d\b", r"\1 would"),
]
informal_patterns = [
    (r"\bwill not\b", "won't"),
    (r"\bcan not\b", "can't"),
    (r"\bcannot\b", "can't"),
    (r"\blet us\b", "let's"),
    (r"\bI am\b", "I'm"),
    (r"\bam not\b", "ain't"),
    (r"\b(\w+) will\b", r"\1'll"),
    (r"\b(\w+) not\b", r"\1n't"),
    (r"\b(\w+) have\b", r"\1've"),
    (r"\b(\w+) is\b", r"\1's"),
    (r"\b(\w+) are\b", r"\1're"),
    (r"\b(\w+) would\b", r"\1'd"),
]

formal_compiled   = [(re.compile(p, re.IGNORECASE), r) for p, r in formal_patterns]
informal_compiled = [(re.compile(p, re.IGNORECASE), r) for p, r in informal_patterns]

#removing repeated letters
repeat_re = re.compile(r"(\w*)(\w)\2{2,}(\w*)")

def normalize_word(w: str) -> str:
    #reduce runs of 3 or more identical letters
    if len(w) < 3:
        return w
    while repeat_re.search(w):
        w = repeat_re.sub(r"\1\2\3", w)
    return w
def clean_repeats(text: str) -> str:
    #preserve whitespace
    result = []
    token = ''
    for ch in text:
        if ch.isalnum():
            token += ch
        else:
            if token:
                result.append(normalize_word(token))
                token = ''
            result.append(ch)
    if token:
        result.append(normalize_word(token))
    return ''.join(result)

#replacer
class Replacer:
    def __init__(self, rules):
        self.rules = rules
    def replace(self, text: str) -> str:
        for pat, rep in self.rules:
            text = pat.sub(rep, text)
        return text
    
#BALIKAN IF NEEDED

formal_repl   = Replacer(formal_compiled)
informal_repl = Replacer(informal_compiled)

#main functions
def main():
    print("\n" + "="*60)
    print("ABSTRACT TRANSFORMATION")
    print("="*60)
    #must input with .txt
    filename = input("\nEnter the filename of the abstract text file: ").strip()
    if not os.path.exists(filename):
        print(f"Error: File '{filename}' not found!")
        return

    with open(filename, 'r', encoding='utf-8') as f:
        original = f.read()

    #fix curly quotes if present 
    original = original.replace("’", "'").replace("‘", "'")

    print("\n" + "-"*50)
    print("original abstract:")
    print("-"*50)
    print(original)

    #fix repeated letters
    no_repeats = clean_repeats(original)

    #generate versions or replace them 
    formal   = formal_repl.replace(no_repeats)
    informal = informal_repl.replace(no_repeats)

  
    print("\n" + "-"*50)
    print("formal version:")
    print("-"*50)
    print(formal)

    print("\n" + "-"*50)
    print("informal versioN:")
    print("-"*50)
    print(informal)

    #save to files
    with open('formal.txt',   'w', encoding='utf-8') as f: f.write(formal)
    with open('informal.txt', 'w', encoding='utf-8') as f: f.write(informal)

    print("\n" + "="*60)
    print("All files saved successfully!")
    print("formal.txt")
    print("informal.txt")
    print("="*60)

if __name__ == "__main__":
    main()


#sample contractions

# | Contraction | Expanded Form |
# | ----------- | ------------- |
# | I'm         | I am          |
# | You're      | You are       |
# | He's        | He is         |
# | She's       | She is        |
# | It's        | It is         |
# | We're       | We are        |
# | They're     | They are      |
# | Who's       | Who is        |
# | That's      | That is       |
# | There's     | There is      |
# | Here's      | Here is       |
# | Where's     | Where is      |
# | How's       | How is        |
