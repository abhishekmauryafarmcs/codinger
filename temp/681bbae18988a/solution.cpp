#include <iostream>
using namespace std;

int main() {
    int numbers[3]; // Fixed-size array to store the input integers.
    int num;

 
    for (int i = 0; i < 3; ++i) {
        if (cin >> numbers[i]) { // Check if reading an integer was successful
           // No need to push_back, we are using a fixed size array.
        } else {
            cout << "Input must contain exactly 3 integers" << endl;
            return 1; // Return a non-zero value to indicate an error
        }
    }

    // Output the reversed array
    cout << "Reversed array: ";
    cout << numbers[2] << " " << numbers[1] << " " << numbers[0] << endl;

    return 0; // Return 0 to indicate successful execution
}
